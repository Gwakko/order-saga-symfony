<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Idempotency;

use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Infrastructure\Persistence\DbTimestamp;
use App\Shared\Infrastructure\Persistence\Row;
use Doctrine\DBAL\Connection;

/**
 * `INSERT ... ON CONFLICT DO NOTHING` decides the winner of a race: the unique `(customer_id, key)` makes exactly one
 * concurrent insert succeed, whatever the interleaving. Every statement runs on its own (autocommit), so the
 * `in_progress` row is visible to other sessions before the request starts executing.
 */
final readonly class DbalIdempotencyStore implements IdempotencyStore
{
    /** Re-tries after deleting an expired row that somebody else may re-insert first. */
    private const int MAX_ATTEMPTS = 5;

    public function __construct(private Connection $connection) {}

    public function begin(
        CustomerId $customerId,
        IdempotencyKey $key,
        string $requestHash,
        string $method,
        string $path,
        \DateTimeImmutable $now,
    ): IdempotencyDecision {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; ++$attempt) {
            $inserted = $this->connection->executeStatement(
                "INSERT INTO idempotency_keys (customer_id, key, request_hash, method, path, status, created_at, expires_at)
                 VALUES (:customer_id, :key, :request_hash, :method, :path, 'in_progress', :created_at, :expires_at)
                 ON CONFLICT (customer_id, key) DO NOTHING",
                [
                    'customer_id' => $customerId->value,
                    'key' => $key->value,
                    'request_hash' => $requestHash,
                    'method' => $method,
                    'path' => $path,
                    'created_at' => DbTimestamp::format($now),
                    'expires_at' => DbTimestamp::format($now->modify(sprintf('+%d seconds', self::TTL_SECONDS))),
                ],
            );

            if ($inserted === 1) {
                return IdempotencyDecision::started();
            }

            $row = $this->connection->fetchAssociative(
                'SELECT request_hash, status, response_status, response_body::text AS response_body, created_at, expires_at
                 FROM idempotency_keys WHERE customer_id = :customer_id AND key = :key',
                ['customer_id' => $customerId->value, 'key' => $key->value],
            );

            if ($row === false) {
                continue; // deleted between the insert and the read: try again
            }

            $found = new Row($row);
            $expired = $found->time('expires_at') <= $now;
            $inProgress = $found->string('status') === 'in_progress';
            $stale = $inProgress && $found->time('created_at') <= $now->modify(sprintf('-%d seconds', self::STALE_IN_PROGRESS_SECONDS));

            if ($expired || $stale) {
                // Only the row that was just read may be removed: a replacement inserted meanwhile has a new created_at.
                $this->connection->executeStatement(
                    'DELETE FROM idempotency_keys WHERE customer_id = :customer_id AND key = :key AND created_at = :created_at',
                    ['customer_id' => $customerId->value, 'key' => $key->value, 'created_at' => $found->string('created_at')],
                );

                continue;
            }

            if (!hash_equals($found->string('request_hash'), $requestHash)) {
                return IdempotencyDecision::keyReused();
            }

            if ($inProgress) {
                return IdempotencyDecision::inProgress();
            }

            return IdempotencyDecision::replay($found->int('response_status'), $found->string('response_body'));
        }

        return IdempotencyDecision::inProgress();
    }

    public function complete(CustomerId $customerId, IdempotencyKey $key, int $status, string $body): void
    {
        $this->connection->executeStatement(
            "UPDATE idempotency_keys SET status = 'completed', response_status = :status, response_body = CAST(:body AS jsonb)
             WHERE customer_id = :customer_id AND key = :key AND status = 'in_progress'",
            ['status' => $status, 'body' => $body, 'customer_id' => $customerId->value, 'key' => $key->value],
        );
    }

    public function release(CustomerId $customerId, IdempotencyKey $key): void
    {
        $this->connection->executeStatement(
            "DELETE FROM idempotency_keys WHERE customer_id = :customer_id AND key = :key AND status = 'in_progress'",
            ['customer_id' => $customerId->value, 'key' => $key->value],
        );
    }
}
