<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\ListCustomerOrders;

use App\Ordering\Domain\OrderStatus;
use App\Shared\Application\Output\MoneyOutput;

final readonly class OrderSummaryOutput
{
    public function __construct(
        public string $orderId,
        public OrderStatus $status,
        public MoneyOutput $price,
        public string $productId,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {}
}
