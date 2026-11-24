<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence;

use App\Ordering\Application\Port\OrderReadModel;
use App\Ordering\Application\Query\GetOrder\OrderDetailsOutput;
use App\Ordering\Application\Query\ListCustomerOrders\OrderCursor;
use App\Ordering\Application\Query\ListCustomerOrders\OrderSummaryOutput;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Application\Output\MoneyOutput;
use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Infrastructure\Persistence\DbTimestamp;
use App\Shared\Infrastructure\Persistence\Row;
use Doctrine\DBAL\Connection;

/**
 * `order_view` over DBAL, no domain objects. Order and cursor match `InMemoryOrderReadModel`:
 * `created_at DESC, order_id DESC`, strictly after the cursor (keyset, no OFFSET).
 */
final readonly class DbalOrderReadModel implements OrderReadModel
{
    public function __construct(private Connection $connection) {}

    public function find(OrderId $orderId, CustomerId $customerId): ?OrderDetailsOutput
    {
        // The key code leaves the system only here, and only for a Completed order (INV-17).
        $raw = $this->connection->fetchAssociative(
            "SELECT v.*, CASE WHEN v.status = 'completed' THEN k.code END AS key_code
             FROM order_view v
             LEFT JOIN product_keys k ON k.id = v.delivered_key_id
             WHERE v.order_id = :order_id AND v.customer_id = :customer_id",
            ['order_id' => $orderId->value, 'customer_id' => $customerId->value],
        );

        if ($raw === false) {
            return null;
        }

        $row = new Row($raw);

        return new OrderDetailsOutput(
            $row->string('order_id'),
            OrderStatus::from($row->string('status')),
            new MoneyOutput($row->int('price_amount'), $row->string('price_currency')),
            $row->string('product_id'),
            $row->nullableString('failure_reason'),
            $row->bool('cancellation_requested'),
            $row->nullableString('key_code'),
            $row->time('created_at'),
            $row->time('updated_at'),
            $row->nullableTime('completed_at'),
        );
    }

    public function list(CustomerId $customerId, ?OrderStatus $status, int $limit, ?OrderCursor $after): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT order_id, status, price_amount, price_currency, product_id, created_at, updated_at
             FROM order_view
             WHERE customer_id = :customer_id
               AND (CAST(:status AS varchar) IS NULL OR status = CAST(:status AS varchar))
               AND (CAST(:after_at AS timestamptz) IS NULL OR (created_at, order_id) < (CAST(:after_at AS timestamptz), CAST(:after_id AS uuid)))
             ORDER BY created_at DESC, order_id DESC
             LIMIT :limit',
            [
                'customer_id' => $customerId->value,
                'status' => $status?->value,
                'after_at' => $after === null ? null : DbTimestamp::format($after->createdAt),
                'after_id' => $after?->orderId->value,
                'limit' => $limit,
            ],
        );

        return array_map(static function (array $raw): OrderSummaryOutput {
            $row = new Row($raw);

            return new OrderSummaryOutput(
                $row->string('order_id'),
                OrderStatus::from($row->string('status')),
                new MoneyOutput($row->int('price_amount'), $row->string('price_currency')),
                $row->string('product_id'),
                $row->time('created_at'),
                $row->time('updated_at'),
            );
        }, $rows);
    }
}
