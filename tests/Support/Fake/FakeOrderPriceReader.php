<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Payment\Application\Port\OrderPriceReader;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Money;

final readonly class FakeOrderPriceReader implements OrderPriceReader
{
    public function __construct(private InMemoryOrderRepository $orders) {}

    public function priceOf(OrderId $orderId): Money
    {
        return $this->orders->get($orderId)->price;
    }
}
