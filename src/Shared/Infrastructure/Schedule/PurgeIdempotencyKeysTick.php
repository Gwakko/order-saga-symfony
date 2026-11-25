<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Schedule;

/**
 * Scheduler message: delete expired idempotency rows once.
 */
final readonly class PurgeIdempotencyKeysTick
{
    public function __construct(public int $limit = 10_000) {}
}
