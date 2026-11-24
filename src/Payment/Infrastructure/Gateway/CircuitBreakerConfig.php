<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Gateway;

/**
 * Thresholds of 00_overview.md section 7.7: open after 5 consecutive failures, or when at least half of at least 10
 * calls inside the 30 s window failed; stay open for 30 s; then one probe call.
 */
final readonly class CircuitBreakerConfig
{
    public function __construct(
        public int $consecutiveFailures = 5,
        public int $failureRatePercent = 50,
        public int $minCallsForRate = 10,
        public int $windowSeconds = 30,
        public int $openSeconds = 30,
        public int $probeTtlSeconds = 15,
    ) {}
}
