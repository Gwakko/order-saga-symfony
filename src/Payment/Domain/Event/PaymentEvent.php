<?php

declare(strict_types=1);

namespace App\Payment\Domain\Event;

use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Domain\Event\EventId;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\PaymentId;
use App\Shared\Domain\Money;

abstract readonly class PaymentEvent implements DomainEvent
{
    public function __construct(
        public EventId $id,
        public PaymentId $paymentId,
        public OrderId $orderId,
        public Money $amount,
        public int $version,
        public \DateTimeImmutable $occurredOn,
    ) {}

    public function eventId(): EventId
    {
        return $this->id;
    }

    public function aggregateId(): string
    {
        return $this->paymentId->value;
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
