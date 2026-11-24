<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Event;

use App\Shared\Domain\Event\EventId;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\FailureReason;

final readonly class OrderNeedsManualReview extends OrderEvent
{
    public function __construct(
        EventId $eventId,
        OrderId $orderId,
        int $version,
        \DateTimeImmutable $occurredOn,
        public FailureReason $reason,
        public string $step,
    ) {
        parent::__construct($eventId, $orderId, $version, $occurredOn);
    }
}
