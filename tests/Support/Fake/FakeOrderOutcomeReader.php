<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Ordering\Domain\OrderStatus;
use App\Payment\Application\Port\OrderOutcomeReader;
use App\Shared\Domain\Identifier\OrderId;

final readonly class FakeOrderOutcomeReader implements OrderOutcomeReader
{
    public function __construct(private InMemoryOrderRepository $orders) {}

    public function endedWithoutKey(OrderId $orderId): bool
    {
        $order = $this->orders->find($orderId);

        return $order !== null && in_array($order->status, [OrderStatus::Failed, OrderStatus::Cancelled], true);
    }
}
