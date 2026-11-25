<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

/**
 * Housekeeping of the HTTP idempotency table (02_use_cases.md section 5, EC-06): rows past `expires_at` are never
 * replayed again, so they only take space.
 */
interface IdempotencyKeyCleaner
{
    /**
     * Deletes up to `$limit` rows with `expires_at <= $now`, oldest first, and returns how many went.
     */
    public function deleteExpired(\DateTimeImmutable $now, int $limit): int;
}
