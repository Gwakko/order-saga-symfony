<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\PhpStan\NoSystemClock;

final class Valid
{
    public function run(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $parsed = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return $now > $parsed ? $now : $parsed;
    }
}
