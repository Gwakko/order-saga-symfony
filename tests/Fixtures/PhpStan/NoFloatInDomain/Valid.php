<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

final readonly class Valid
{
    public function __construct(public int $amount, public ?int $nullable = null) {}

    public function addVat(int $percent): int
    {
        return intdiv($this->amount * (100 + $percent), 100);
    }

    public function label(): string
    {
        return sprintf('%d.%02d', intdiv($this->amount, 100), $this->amount % 100);
    }
}
