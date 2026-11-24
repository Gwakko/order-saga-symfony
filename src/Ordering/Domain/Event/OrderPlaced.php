<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Event;

use App\Shared\Domain\Event\EventId;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Money;

final readonly class OrderPlaced extends OrderEvent
{
    public function __construct(
        EventId $eventId,
        OrderId $orderId,
        int $version,
        \DateTimeImmutable $occurredOn,
        public CustomerId $customerId,
        public ProductId $productId,
        public Money $price,
    ) {
        parent::__construct($eventId, $orderId, $version, $occurredOn);
    }
}
