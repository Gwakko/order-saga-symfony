<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Idempotency;

use App\Shared\Application\Port\IdempotencyKeyCleaner;
use App\Shared\Infrastructure\Persistence\DbTimestamp;
use Doctrine\DBAL\Connection;

/**
 * One bounded `DELETE` over the `idempotency_keys_expiry_idx` index. The same boundary as `DbalIdempotencyStore::begin`
 * (`expires_at <= now` is expired), so a row the store would still replay is never deleted.
 */
final readonly class DbalIdempotencyKeyCleaner implements IdempotencyKeyCleaner
{
    public function __construct(private Connection $connection) {}

    public function deleteExpired(\DateTimeImmutable $now, int $limit): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM idempotency_keys
             WHERE (customer_id, key) IN (
                 SELECT customer_id, key FROM idempotency_keys WHERE expires_at <= :now ORDER BY expires_at LIMIT :limit
             )',
            ['now' => DbTimestamp::format($now), 'limit' => $limit],
        );
    }
}
