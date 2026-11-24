<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Gateway;

/**
 * Breaker state shared by all workers. Every method is one atomic step so that parallel processes cannot lose a
 * failure or both win the half-open probe. Times are milliseconds since the epoch, taken from the Clock by the caller.
 */
interface CircuitStateStore
{
    /**
     * When the circuit was opened; null while it is closed.
     *
     * @throws CircuitStateStoreUnavailable
     */
    public function openedAt(string $circuit): ?int;

    /**
     * Opens (or re-opens) the circuit and forgets the counters and any probe.
     *
     * @throws CircuitStateStoreUnavailable
     */
    public function open(string $circuit, int $nowMs): void;

    /**
     * Closes the circuit and forgets the counters and any probe.
     *
     * @throws CircuitStateStoreUnavailable
     */
    public function close(string $circuit): void;

    /**
     * Appends one call to the sliding window (calls older than `$windowMs` are dropped) and updates the failure streak.
     *
     * @throws CircuitStateStoreUnavailable
     */
    public function recordCall(string $circuit, bool $failure, int $nowMs, int $windowMs): CircuitCallStats;

    /**
     * True for exactly one caller until the probe is released or `$ttlMs` passes (`SET NX PX` in Redis).
     *
     * @throws CircuitStateStoreUnavailable
     */
    public function tryAcquireProbe(string $circuit, int $nowMs, int $ttlMs): bool;
}
