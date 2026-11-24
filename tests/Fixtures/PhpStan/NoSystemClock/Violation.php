<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\PhpStan\NoSystemClock;

final class Violation
{
    public function run(): void
    {
        time();
        date('Y');
        microtime(true);
        hrtime(true);
        new \DateTimeImmutable();
        new \DateTime('now');
        date_create();
    }
}
