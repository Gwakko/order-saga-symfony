<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Gateway;

use App\Payment\Application\Exception\PaymentGatewayUnavailable;
use App\Payment\Application\Exception\PaymentOutcomeUnknown;
use App\Payment\Application\Port\ChargeResult;
use App\Payment\Application\Port\GatewayChargeStatus;
use App\Payment\Application\Port\PaymentGateway;
use App\Payment\Application\Port\RefundResult;
use App\Shared\Domain\Currency;
use App\Shared\Domain\DomainError;
use App\Shared\Domain\Money;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Payment gateway over HTTP (Symfony HttpClient, 10 s timeout). No retries here: Temporal retries the activity.
 *
 * `Idempotency-Key` is always sent. For a charge every answer that does not prove the outcome is
 * PaymentOutcomeUnknown, never a refusal: a timeout, a dropped connection, any 5xx (the gateway may have booked the
 * charge before failing, EC-26), a body that is not complete JSON, a missing `chargeId`, an unknown status (EC-25,
 * EC-30). Only a 402 with `declined` is a refusal. `findCharge` and `refund` are safe to repeat (lookup, idempotent
 * key), so their failures are PaymentGatewayUnavailable.
 */
final readonly class HttpPaymentGateway implements PaymentGateway
{
    public function __construct(
        private HttpClientInterface $client,
        private string $baseUri,
        private float $timeoutSeconds = 10.0,
    ) {}

    public function charge(string $idempotencyKey, Money $amount): ChargeResult
    {
        try {
            $answer = $this->send('POST', '/charges', $idempotencyKey, $this->moneyBody($amount));
        } catch (TransportExceptionInterface $e) {
            throw new PaymentOutcomeUnknown('The gateway did not answer the charge in time or dropped the connection.', $e);
        }

        return match (true) {
            $answer->status === 200, $answer->status === 402 => $this->chargeFrom($answer) ?? throw new PaymentOutcomeUnknown(
                sprintf('The gateway answer to the charge cannot be read (HTTP %d).', $answer->status),
            ),
            $answer->status >= 500 => throw new PaymentOutcomeUnknown(sprintf('The gateway failed after the charge was sent (HTTP %d).', $answer->status)),
            $answer->status >= 400 => throw new GatewayRequestRejected(sprintf('The gateway rejected the charge (HTTP %d).', $answer->status)),
            default => throw new PaymentOutcomeUnknown(sprintf('Unexpected HTTP %d for a charge.', $answer->status)),
        };
    }

    public function findCharge(string $idempotencyKey): ?ChargeResult
    {
        try {
            $answer = $this->send('GET', '/charges/' . rawurlencode($idempotencyKey), $idempotencyKey, null);
        } catch (TransportExceptionInterface $e) {
            throw new PaymentGatewayUnavailable('The gateway did not answer the charge lookup.', $e);
        }

        if ($answer->status === 404 && ($answer->json['error'] ?? null) === 'charge_not_found') {
            return null;
        }

        if ($answer->status !== 200) {
            throw new PaymentGatewayUnavailable(sprintf('The gateway answered the charge lookup with HTTP %d.', $answer->status));
        }

        return $this->chargeFrom($answer) ?? throw new PaymentGatewayUnavailable('The gateway answer to the charge lookup cannot be read or the charge is still pending.');
    }

    public function refund(string $idempotencyKey, string $chargeId, Money $amount): RefundResult
    {
        try {
            $answer = $this->send('POST', '/refunds', $idempotencyKey, ['chargeId' => $chargeId] + $this->moneyBody($amount));
        } catch (TransportExceptionInterface $e) {
            throw new PaymentGatewayUnavailable('The gateway did not answer the refund in time.', $e);
        }

        return match (true) {
            $answer->status === 200 => $this->refundFrom($answer) ?? throw new PaymentGatewayUnavailable('The gateway answer to the refund cannot be read.'),
            $answer->status >= 400 && $answer->status < 500 => throw new GatewayRequestRejected(sprintf('The gateway rejected the refund (HTTP %d).', $answer->status)),
            default => throw new PaymentGatewayUnavailable(sprintf('The gateway answered the refund with HTTP %d.', $answer->status)),
        };
    }

    /**
     * @param array<string, int|string>|null $body
     *
     * @throws TransportExceptionInterface
     */
    private function send(string $method, string $path, string $idempotencyKey, ?array $body): GatewayAnswer
    {
        $options = [
            'headers' => ['Idempotency-Key' => $idempotencyKey, 'Accept' => 'application/json'],
            'timeout' => $this->timeoutSeconds,
            'max_duration' => $this->timeoutSeconds,
        ];

        if ($body !== null) {
            $options['json'] = $body;
        }

        $response = $this->client->request($method, rtrim($this->baseUri, '/') . $path, $options);
        $status = $response->getStatusCode();
        $content = $response->getContent(false);
        $contentType = $response->getHeaders(false)['content-type'][0] ?? '';

        return new GatewayAnswer($status, self::decode($content, $contentType));
    }

    /**
     * @return array<string, mixed>|null null when the body is not a complete JSON object
     */
    private static function decode(string $content, string $contentType): ?array
    {
        if (!str_contains(strtolower($contentType), 'json')) {
            return null;
        }

        try {
            $decoded = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            return null;
        }

        $object = [];

        foreach ($decoded as $key => $value) {
            $object[(string) $key] = $value;
        }

        return $object;
    }

    /**
     * @return array{amount: int, currency: string}
     */
    private function moneyBody(Money $amount): array
    {
        return ['amount' => $amount->amount, 'currency' => $amount->currency->value];
    }

    /**
     * Null when the answer does not describe a settled charge.
     */
    private function chargeFrom(GatewayAnswer $answer): ?ChargeResult
    {
        $json = $answer->json;

        if ($json === null) {
            return null;
        }

        $chargeId = $json['chargeId'] ?? null;
        $status = is_string($json['status'] ?? null) ? GatewayChargeStatus::tryFrom($json['status']) : null;
        $amount = self::money($json);

        if (!is_string($chargeId) || $chargeId === '' || $status === null || $amount === null) {
            return null;
        }

        // A 402 must be a refusal and a 200 must not be one: anything else is not what the contract says.
        if (($answer->status === 402) !== ($status === GatewayChargeStatus::Declined)) {
            return null;
        }

        $reason = $json['reason'] ?? null;

        return new ChargeResult($chargeId, $status, $amount, is_string($reason) ? $reason : null);
    }

    private function refundFrom(GatewayAnswer $answer): ?RefundResult
    {
        $json = $answer->json;
        $refundId = $json['refundId'] ?? null;
        $amount = $json === null ? null : self::money($json);

        if (!is_string($refundId) || $refundId === '' || $amount === null || ($json['status'] ?? null) !== 'refunded') {
            return null;
        }

        return new RefundResult($refundId, $amount);
    }

    /**
     * @param array<string, mixed> $json
     */
    private static function money(array $json): ?Money
    {
        $amount = $json['amount'] ?? null;
        $currency = is_string($json['currency'] ?? null) ? Currency::tryFrom($json['currency']) : null;

        if (!is_int($amount) || $currency === null) {
            return null;
        }

        try {
            return Money::of($amount, $currency);
        } catch (DomainError) {
            return null;
        }
    }
}
