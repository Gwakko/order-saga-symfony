<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Schedule;

/**
 * Scheduler message: sweep orders whose saga is gone once. The age threshold comes from configuration
 * (`app.stuck_orders.older_than_seconds`), not from the message.
 */
final readonly class SweepStuckOrdersTick
{
    public function __construct(public int $limit = 100) {}
}
