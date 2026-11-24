<?php

declare(strict_types=1);

namespace GatewayEmulator;

/** Маршрутизация и логика эмулятора. Не зависит от HTTP-сервера: Request -> Response. */
final readonly class Handler
{
    private const string WRONG_CURRENCY = 'USD';
    private const string FALLBACK_WRONG_CURRENCY = 'EUR';

    /** @param \Closure(): int $nowMs текущее время в миллисекундах */
    public function __construct(
        private Store $store,
        private \Closure $nowMs,
    ) {}

    public function handle(Request $request): Response
    {
        $path = $request->path;

        if (preg_match('#^/charges/([^/]+)$#', $path, $m) === 1) {
            return $request->method === 'GET' ? $this->findCharge(rawurldecode($m[1])) : $this->methodNotAllowed();
        }

        return match ($path) {
            '/health' => Response::json(200, ['status' => 'ok']),
            '/charges' => $request->method === 'POST' ? $this->charge($request) : $this->methodNotAllowed(),
            '/refunds' => $request->method === 'POST' ? $this->refund($request) : $this->methodNotAllowed(),
            '/__admin/mode' => $request->method === 'POST' ? $this->setMode($request) : $this->methodNotAllowed(),
            '/__admin/reset' => $request->method === 'POST' ? $this->reset() : $this->methodNotAllowed(),
            '/__admin/stats' => $request->method === 'GET' ? $this->stats() : $this->methodNotAllowed(),
            default => Response::json(404, ['error' => 'not_found']),
        };
    }

    private function charge(Request $request): Response
    {
        $key = $request->idempotencyKey;

        if ($key === null || $key === '') {
            return Response::json(400, ['error' => 'idempotency_key_required']);
        }

        $body = $this->decode($request->body);
        $amount = $body['amount'] ?? null;
        $currency = $body['currency'] ?? null;

        if (!is_int($amount) || $amount <= 0 || !is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return Response::json(422, ['error' => 'invalid_request', 'message' => 'amount: positive integer (minor units), currency: ISO-4217 upper case']);
        }

        return $this->store->transaction(function () use ($key, $amount, $currency): Response {
            $mode = $this->store->mode();

            if ($outage = $this->outage($mode)) {
                return $outage;
            }

            $existing = $this->store->charge($key);

            if ($existing === null) {
                $existing = $this->createCharge($key, $amount, $currency, $mode);
            } elseif ($existing['request_amount'] !== $amount || $existing['request_currency'] !== $currency) {
                return Response::json(409, ['error' => 'idempotency_key_reused_with_different_request']);
            } else {
                $this->store->touchCharge($key);
            }

            return $this->shape($mode, $this->chargeView($existing), 'chargeId', declined: $existing['status'] === 'declined');
        });
    }

    /** @return array<string, mixed> */
    private function createCharge(string $key, int $amount, string $currency, Mode $mode): array
    {
        [$chargedAmount, $chargedCurrency] = match ($mode->kind) {
            ModeKind::WrongAmount => [$amount + 1, $currency],
            ModeKind::WrongCurrency => [$amount, $currency === self::WRONG_CURRENCY ? self::FALLBACK_WRONG_CURRENCY : self::WRONG_CURRENCY],
            default => [$amount, $currency],
        };
        $status = match ($mode->kind) {
            ModeKind::Decline => 'declined',
            ModeKind::UnknownStatus => 'weird',
            ModeKind::LateSuccess => 'pending',
            default => 'succeeded',
        };
        $completeAt = $mode->kind === ModeKind::LateSuccess ? ($this->nowMs)() + $mode->parameter : 0;
        $chargeId = 'ch_' . bin2hex(random_bytes(8));

        $this->store->insertCharge($key, $chargeId, $amount, $currency, $chargedAmount, $chargedCurrency, $status, $completeAt, $status !== 'declined');

        return $this->store->charge($key) ?? throw new \LogicException('charge not stored');
    }

    private function findCharge(string $key): Response
    {
        $charge = $this->store->charge($key);

        if ($charge === null) {
            return Response::json(404, ['error' => 'charge_not_found']);
        }

        return Response::json(200, $this->chargeView($charge));
    }

    /**
     * @param array<string, mixed> $charge
     * @return array<string, mixed>
     */
    private function chargeView(array $charge): array
    {
        $status = $charge['status'] === 'pending' && ($this->nowMs)() >= $charge['complete_at_ms'] ? 'succeeded' : $charge['status'];

        $view = [
            'chargeId' => $charge['charge_id'],
            'idempotencyKey' => $charge['idempotency_key'],
            'status' => $status,
            'amount' => $charge['amount'],
            'currency' => $charge['currency'],
        ];

        return $status === 'declined' ? $view + ['reason' => 'insufficient_funds'] : $view;
    }

    private function refund(Request $request): Response
    {
        $key = $request->idempotencyKey;

        if ($key === null || $key === '') {
            return Response::json(400, ['error' => 'idempotency_key_required']);
        }

        $body = $this->decode($request->body);
        $chargeId = $body['chargeId'] ?? null;
        $amount = $body['amount'] ?? null;
        $currency = $body['currency'] ?? null;

        if (!is_string($chargeId) || !is_int($amount) || $amount <= 0 || !is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return Response::json(422, ['error' => 'invalid_request', 'message' => 'chargeId: string, amount: positive integer (minor units), currency: ISO-4217 upper case']);
        }

        return $this->store->transaction(function () use ($key, $chargeId, $amount, $currency): Response {
            $mode = $this->store->mode();

            if ($outage = $this->outage($mode)) {
                return $outage;
            }

            $existing = $this->store->refund($key);

            if ($existing === null) {
                $charge = $this->store->chargeById($chargeId);

                if ($charge === null) {
                    return Response::json(404, ['error' => 'charge_not_found']);
                }

                $view = $this->chargeView($charge);

                if ($view['status'] !== 'succeeded' || $amount > $charge['amount']) {
                    return Response::json(422, ['error' => 'refund_not_allowed', 'message' => 'charge is not succeeded or amount exceeds charged amount']);
                }

                $status = $mode->kind === ModeKind::UnknownStatus ? 'weird' : 'refunded';
                $this->store->insertRefund($key, 'rf_' . bin2hex(random_bytes(8)), $chargeId, $amount, $currency, $status);
                $existing = $this->store->refund($key) ?? throw new \LogicException('refund not stored');
            } elseif ($existing['charge_id'] !== $chargeId || $existing['amount'] !== $amount || $existing['currency'] !== $currency) {
                return Response::json(409, ['error' => 'idempotency_key_reused_with_different_request']);
            } else {
                $this->store->touchRefund($key);
            }

            return $this->shape($mode, [
                'refundId' => $existing['refund_id'],
                'chargeId' => $existing['charge_id'],
                'idempotencyKey' => $existing['idempotency_key'],
                'status' => $existing['status'],
                'amount' => $existing['amount'],
                'currency' => $existing['currency'],
            ], 'refundId', declined: false);
        });
    }

    /** Сбои до обработки запроса: ничего не списывается. Действуют и на повторы. */
    private function outage(Mode $mode): ?Response
    {
        return match ($mode->kind) {
            ModeKind::Http500 => Response::json(500, ['error' => 'internal_error']),
            ModeKind::Http503 => $this->consumeUnavailable($mode),
            default => null,
        };
    }

    private function consumeUnavailable(Mode $mode): ?Response
    {
        $left = $mode->parameter - 1;
        $this->store->setMode($left > 0 ? new Mode(ModeKind::Http503, $left) : Mode::ok());

        return Response::json(503, ['error' => 'service_unavailable']);
    }

    /**
     * Форма ответа после того, как операция выполнена (или найдена по ключу).
     *
     * @param array<string, mixed> $view
     */
    private function shape(Mode $mode, array $view, string $idField, bool $declined): Response
    {
        $json = json_encode($view, JSON_THROW_ON_ERROR);
        $ok = Response::json($declined ? 402 : 200, $view);

        return match ($mode->kind) {
            ModeKind::AcceptThen502 => Response::json(502, ['error' => 'bad_gateway']),
            ModeKind::TruncatedJson => new Response(200, substr($json, 0, intdiv(strlen($json), 2))),
            ModeKind::NoChargeId => Response::json(200, array_diff_key($view, [$idField => true])),
            ModeKind::DropConnection => new Response(200, substr($json, 0, 10), abort: true),
            ModeKind::Delay => $ok->withDelay($mode->parameter),
            default => $ok,
        };
    }

    private function setMode(Request $request): Response
    {
        $raw = $this->decode($request->body)['mode'] ?? null;
        $mode = is_string($raw) ? Mode::parse($raw) : null;

        if ($mode === null) {
            return Response::json(422, ['error' => 'unknown_mode']);
        }

        $this->store->transaction(function () use ($mode): void {
            $this->store->setMode($mode->kind === ModeKind::Http503 && $mode->parameter === 0 ? Mode::ok() : $mode);
        });

        return Response::json(200, ['mode' => $mode->toString()]);
    }

    private function reset(): Response
    {
        $this->store->transaction($this->store->reset(...));

        return Response::json(200, ['mode' => 'ok']);
    }

    private function stats(): Response
    {
        $charges = [];
        $refunds = [];
        $chargeTotal = 0;
        $refundTotal = 0;

        foreach ($this->store->all('charges') as $row) {
            $charges[(string) $row['idempotency_key']] = ['requests' => $row['requests'], 'executed' => $row['executed']];
            $chargeTotal += (int) $row['executed'];
        }

        foreach ($this->store->all('refunds') as $row) {
            $refunds[(string) $row['idempotency_key']] = ['requests' => $row['requests'], 'executed' => $row['executed']];
            $refundTotal += (int) $row['executed'];
        }

        return Response::json(200, [
            'mode' => $this->store->mode()->toString(),
            'charges' => (object) $charges,
            'refunds' => (object) $refunds,
            'totals' => ['charges' => $chargeTotal, 'refunds' => $refundTotal],
        ]);
    }

    /** @return array<string, mixed> */
    private function decode(string $body): array
    {
        $data = json_decode($body, true);

        return is_array($data) ? $data : [];
    }

    private function methodNotAllowed(): Response
    {
        return Response::json(405, ['error' => 'method_not_allowed']);
    }
}
