<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Ordering\Application\Port\OrderReadModel;
use App\Ordering\Application\Query\GetOrder\OrderDetailsOutput;
use App\Ordering\Application\Query\ListCustomerOrders\OrderCursor;
use App\Ordering\Application\Query\ListCustomerOrders\OrderSummaryOutput;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Domain\Identifier\OrderId;

/**
 * Follows the contract of the SQL read model: owner filter, `created_at DESC, order_id DESC`, keyset cursor.
 */
final class InMemoryOrderReadModel implements OrderReadModel
{
    /** @var list<array{customerId: string, order: OrderDetailsOutput}> */
    private array $rows = [];

    public function seed(CustomerId $customerId, OrderDetailsOutput $order): void
    {
        $this->rows[] = ['customerId' => $customerId->value, 'order' => $order];
    }

    public function find(OrderId $orderId, CustomerId $customerId): ?OrderDetailsOutput
    {
        foreach ($this->rows as $row) {
            if ($row['order']->orderId === $orderId->value && $row['customerId'] === $customerId->value) {
                return $row['order'];
            }
        }

        return null;
    }

    public function list(CustomerId $customerId, ?OrderStatus $status, int $limit, ?OrderCursor $after): array
    {
        $orders = array_values(array_filter(
            array_map(static fn(array $row): OrderDetailsOutput => $row['order'], array_filter($this->rows, static fn(array $row): bool => $row['customerId'] === $customerId->value)),
            static fn(OrderDetailsOutput $o): bool => $status === null || $o->status === $status,
        ));

        usort($orders, static fn(OrderDetailsOutput $a, OrderDetailsOutput $b): int => [$b->createdAt, $b->orderId] <=> [$a->createdAt, $a->orderId]);

        if ($after !== null) {
            $orders = array_values(array_filter(
                $orders,
                static fn(OrderDetailsOutput $o): bool => [$o->createdAt, $o->orderId] < [$after->createdAt, $after->orderId->value],
            ));
        }

        return array_map(
            static fn(OrderDetailsOutput $o): OrderSummaryOutput => new OrderSummaryOutput($o->orderId, $o->status, $o->price, $o->productId, $o->createdAt, $o->updatedAt),
            array_slice($orders, 0, $limit),
        );
    }
}
