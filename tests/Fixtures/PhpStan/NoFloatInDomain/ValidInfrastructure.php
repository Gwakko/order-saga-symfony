<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

final class ValidInfrastructure
{
    public float $latencySeconds = 0.5;

    public function toSeconds(int $milliseconds): float
    {
        return $milliseconds / 1000.0;
    }
}
