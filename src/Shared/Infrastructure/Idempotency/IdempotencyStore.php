<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Idempotency;

use App\Shared\Domain\Identifier\CustomerId;

/**
 * Storage side of the HTTP idempotency algorithm (02_use_cases.md section 5), keyed by `(customer, key)`.
 */
interface IdempotencyStore
{
    /** An `in_progress` row older than this is a crashed request and counts as expired (EC-07b). */
    public const int STALE_IN_PROGRESS_SECONDS = 60;

    /** How long a completed answer is replayed (EC-06). */
    public const int TTL_SECONDS = 86_400;

    public function begin(
        CustomerId $customerId,
        IdempotencyKey $key,
        string $requestHash,
        string $method,
        string $path,
        \DateTimeImmutable $now,
    ): IdempotencyDecision;

    public function complete(CustomerId $customerId, IdempotencyKey $key, int $status, string $body): void;

    /** The request failed (5xx or exception): forget the key so a retry runs again (EC-07). */
    public function release(CustomerId $customerId, IdempotencyKey $key): void;
}
