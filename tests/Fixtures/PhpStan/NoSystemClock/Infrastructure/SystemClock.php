<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\PhpStan\NoSystemClock\Infrastructure\Clock;

final class SystemClock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
