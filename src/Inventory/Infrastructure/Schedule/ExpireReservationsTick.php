<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Schedule;

/**
 * Scheduler message: run the reservation sweeper once.
 */
final readonly class ExpireReservationsTick
{
    public function __construct(public int $limit = 500) {}
}
