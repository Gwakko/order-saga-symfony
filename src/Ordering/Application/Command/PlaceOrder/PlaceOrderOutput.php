<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\PlaceOrder;

use App\Ordering\Domain\OrderStatus;
use App\Shared\Application\Output\MoneyOutput;

final readonly class PlaceOrderOutput
{
    public function __construct(
        public string $orderId,
        public OrderStatus $status,
        public MoneyOutput $price,
        public \DateTimeImmutable $createdAt,
    ) {}
}
