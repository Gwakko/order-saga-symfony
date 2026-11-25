<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Ordering\Application\Port\StuckOrder;
use App\Ordering\Application\Port\StuckOrderFinder;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderStatus;

/**
 * Same rule as `DbalStuckOrderFinder`, over the in-memory order repository.
 */
final readonly class InMemoryStuckOrderFinder implements StuckOrderFinder
{
    private const array INTERMEDIATE = [OrderStatus::Pending, OrderStatus::KeyReserved, OrderStatus::Paid, OrderStatus::Compensating, OrderStatus::Refunding];

    public function __construct(private InMemoryOrderRepository $orders) {}

    public function findUpdatedBefore(\DateTimeImmutable $updatedBefore, int $limit): array
    {
        $stuck = array_filter(
            $this->orders->all(),
            static fn(Order $order): bool => in_array($order->status, self::INTERMEDIATE, true) && $order->updatedAt < $updatedBefore,
        );
        usort($stuck, static fn(Order $a, Order $b): int => $a->updatedAt <=> $b->updatedAt);

        return array_map(
            static fn(Order $order): StuckOrder => new StuckOrder($order->id, $order->status),
            array_slice($stuck, 0, $limit),
        );
    }
}
