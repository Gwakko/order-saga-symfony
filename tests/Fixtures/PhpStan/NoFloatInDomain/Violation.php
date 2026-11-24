<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

final class Violation
{
    public float $property = 0;

    public ?float $nullable = null;

    public int|float $union = 0;

    public function __construct(public readonly float $promoted) {}

    public function parameter(float $value): int
    {
        return (int) $value;
    }

    public function returnType(): float
    {
        return 1;
    }

    public function literal(int $amount): int
    {
        return (int) ($amount * 1.2);
    }

    public function cast(string $raw): int
    {
        $value = (float) $raw;

        return (int) $value;
    }

    public function closure(): \Closure
    {
        return static fn(int $x): float => $x;
    }
}
