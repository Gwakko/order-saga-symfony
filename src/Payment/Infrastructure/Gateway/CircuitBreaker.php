<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Gateway;

use App\Payment\Application\Exception\CircuitOpen;
use App\Payment\Application\Exception\PaymentGatewayUnavailable;
use App\Payment\Application\Exception\PaymentOutcomeUnknown;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Circuit breaker for the payment gateway (00_overview.md section 7.7, EC-31, EC-33, EC-35).
 *
 * Closed: calls go through; a timeout, a network failure or a 5xx (PaymentOutcomeUnknown, PaymentGatewayUnavailable)
 * is a failure, a bank decline or a 4xx is not. Open after N consecutive failures or when at least X% of the last
 * M calls inside the window failed. Open for the configured time, then half-open: exactly one probe call goes
 * through (the store hands out the probe once), the others get CircuitOpen; the probe's success closes the circuit,
 * its failure opens it again. If the store is unreachable the breaker degrades to closed and logs a warning.
 */
final readonly class CircuitBreaker
{
    public function __construct(
        private string $name,
        private CircuitStateStore $store,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private CircuitBreakerConfig $config = new CircuitBreakerConfig(),
    ) {}

    public function state(): CircuitState
    {
        try {
            $openedAt = $this->store->openedAt($this->name);
        } catch (CircuitStateStoreUnavailable) {
            return CircuitState::Closed;
        }

        return match (true) {
            $openedAt === null => CircuitState::Closed,
            $this->nowMs() < $openedAt + $this->config->openSeconds * 1000 => CircuitState::Open,
            default => CircuitState::HalfOpen,
        };
    }

    /**
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     *
     * @throws CircuitOpen the operation was not started
     * @throws \Throwable whatever the operation throws, after it was counted
     */
    public function call(\Closure $operation): mixed
    {
        $openedAt = $this->guarded(fn(): ?int => $this->store->openedAt($this->name), null);

        if ($openedAt === null) {
            return $this->closedCall($operation);
        }

        $now = $this->nowMs();

        if ($now < $openedAt + $this->config->openSeconds * 1000) {
            throw new CircuitOpen();
        }

        return $this->probeCall($operation, $now);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    private function closedCall(\Closure $operation): mixed
    {
        try {
            $result = $operation();
        } catch (\Throwable $e) {
            if (self::isFailure($e)) {
                $this->recordFailure();
            } else {
                $this->recordSuccess();
            }

            throw $e;
        }

        $this->recordSuccess();

        return $result;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    private function probeCall(\Closure $operation, int $now): mixed
    {
        $acquired = $this->guarded(
            fn(): bool => $this->store->tryAcquireProbe($this->name, $now, $this->config->probeTtlSeconds * 1000),
            true,
        );

        if (!$acquired) {
            throw new CircuitOpen();
        }

        try {
            $result = $operation();
        } catch (\Throwable $e) {
            $this->guarded(
                fn() => self::isFailure($e) ? $this->store->open($this->name, $this->nowMs()) : $this->store->close($this->name),
                null,
            );

            throw $e;
        }

        $this->guarded(fn() => $this->store->close($this->name), null);

        return $result;
    }

    private function recordSuccess(): void
    {
        $this->guarded(fn() => $this->store->recordCall($this->name, false, $this->nowMs(), $this->windowMs()), null);
    }

    private function recordFailure(): void
    {
        $stats = $this->guarded(fn(): CircuitCallStats => $this->store->recordCall($this->name, true, $this->nowMs(), $this->windowMs()), null);

        if ($stats === null || !$this->shouldOpen($stats)) {
            return;
        }

        $this->guarded(fn() => $this->store->open($this->name, $this->nowMs()), null);
        $this->logger->warning('Payment circuit opened.', [
            'circuit' => $this->name,
            'consecutive_failures' => $stats->consecutiveFailures,
            'calls' => $stats->calls,
            'failures' => $stats->failures,
        ]);
    }

    private function shouldOpen(CircuitCallStats $stats): bool
    {
        if ($stats->consecutiveFailures >= $this->config->consecutiveFailures) {
            return true;
        }

        return $stats->calls >= $this->config->minCallsForRate
            && $stats->failures * 100 >= $stats->calls * $this->config->failureRatePercent;
    }

    private static function isFailure(\Throwable $e): bool
    {
        return $e instanceof PaymentOutcomeUnknown || $e instanceof PaymentGatewayUnavailable;
    }

    /**
     * Runs a store call; when the store is down the breaker degrades to closed and returns `$fallback` (EC-35).
     *
     * @template T
     * @template F
     *
     * @param \Closure(): T $storeCall
     * @param F $fallback
     *
     * @return T|F
     */
    private function guarded(\Closure $storeCall, mixed $fallback): mixed
    {
        try {
            return $storeCall();
        } catch (CircuitStateStoreUnavailable $e) {
            $this->logger->warning('Circuit breaker state is unavailable, calls pass through.', [
                'circuit' => $this->name,
                'error' => $e->getMessage(),
            ]);

            return $fallback;
        }
    }

    private function nowMs(): int
    {
        return (int) $this->clock->now()->format('Uv');
    }

    private function windowMs(): int
    {
        return $this->config->windowSeconds * 1000;
    }
}
