<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence;

use App\Ordering\Application\Port\StuckOrder;
use App\Ordering\Application\Port\StuckOrderFinder;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Infrastructure\Persistence\DbTimestamp;
use App\Shared\Infrastructure\Persistence\Row;
use Doctrine\DBAL\Connection;

/**
 * Reads the `orders` table (the aggregate, not the eventually consistent `order_view`).
 */
final readonly class DbalStuckOrderFinder implements StuckOrderFinder
{
    public function __construct(private Connection $connection) {}

    public function findUpdatedBefore(\DateTimeImmutable $updatedBefore, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, status FROM orders
             WHERE status IN ('pending', 'key_reserved', 'paid', 'compensating', 'refunding') AND updated_at < :before
             ORDER BY updated_at, id
             LIMIT :limit",
            ['before' => DbTimestamp::format($updatedBefore), 'limit' => $limit],
        );

        return array_map(static function (array $row): StuckOrder {
            $row = new Row($row);

            return new StuckOrder(OrderId::fromString($row->string('id')), OrderStatus::from($row->string('status')));
        }, $rows);
    }
}
