<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\GetOrder;

use App\Ordering\Domain\OrderStatus;
use App\Shared\Application\Output\MoneyOutput;

/**
 * `keyCode` is the only place the code leaves the system (INV-17): set only while the order is `Completed`.
 */
final readonly class OrderDetailsOutput
{
    public function __construct(
        public string $orderId,
        public OrderStatus $status,
        public MoneyOutput $price,
        public string $productId,
        public ?string $failureReason,
        public bool $cancellationRequested,
        public ?string $keyCode,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ?\DateTimeImmutable $completedAt,
    ) {}
}
