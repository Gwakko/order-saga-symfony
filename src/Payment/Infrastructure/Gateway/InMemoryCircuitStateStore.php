<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Gateway;

/**
 * Single-process store for tests and local runs. `breakStore()` simulates a store outage (EC-35).
 */
final class InMemoryCircuitStateStore implements CircuitStateStore
{
    /** @var array<string, int> */
    private array $openedAt = [];

    /** @var array<string, int> */
    private array $streak = [];

    /** @var array<string, list<array{int, bool}>> */
    private array $calls = [];

    /** @var array<string, int> probe expiry in ms */
    private array $probeUntil = [];

    private bool $broken = false;

    public function breakStore(bool $broken = true): void
    {
        $this->broken = $broken;
    }

    public function openedAt(string $circuit): ?int
    {
        $this->guard();

        return $this->openedAt[$circuit] ?? null;
    }

    public function open(string $circuit, int $nowMs): void
    {
        $this->guard();
        $this->reset($circuit);
        $this->openedAt[$circuit] = $nowMs;
    }

    public function close(string $circuit): void
    {
        $this->guard();
        $this->reset($circuit);
    }

    public function recordCall(string $circuit, bool $failure, int $nowMs, int $windowMs): CircuitCallStats
    {
        $this->guard();

        $window = array_values(array_filter(
            $this->calls[$circuit] ?? [],
            static fn(array $call): bool => $call[0] > $nowMs - $windowMs,
        ));
        $window[] = [$nowMs, $failure];
        $this->calls[$circuit] = $window;
        $this->streak[$circuit] = $failure ? ($this->streak[$circuit] ?? 0) + 1 : 0;

        return new CircuitCallStats(
            $this->streak[$circuit],
            count($window),
            count(array_filter($window, static fn(array $call): bool => $call[1])),
        );
    }

    public function tryAcquireProbe(string $circuit, int $nowMs, int $ttlMs): bool
    {
        $this->guard();

        if (($this->probeUntil[$circuit] ?? 0) > $nowMs) {
            return false;
        }

        $this->probeUntil[$circuit] = $nowMs + $ttlMs;

        return true;
    }

    private function reset(string $circuit): void
    {
        unset($this->openedAt[$circuit], $this->streak[$circuit], $this->calls[$circuit], $this->probeUntil[$circuit]);
    }

    private function guard(): void
    {
        if ($this->broken) {
            throw new CircuitStateStoreUnavailable('In-memory store switched off.');
        }
    }
}
