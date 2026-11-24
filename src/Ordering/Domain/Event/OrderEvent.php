<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Event;

use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Domain\Event\EventId;
use App\Shared\Domain\Identifier\OrderId;

abstract readonly class OrderEvent implements DomainEvent
{
    public function __construct(
        public EventId $id,
        public OrderId $orderId,
        public int $version,
        public \DateTimeImmutable $occurredOn,
    ) {}

    public function eventId(): EventId
    {
        return $this->id;
    }

    public function aggregateId(): string
    {
        return $this->orderId->value;
    }

    public function aggregateVersion(): int
    {
        return $this->version;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredOn;
    }
}
