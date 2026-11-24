<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Event;

use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Domain\Event\EventId;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Identifier\ProductKeyId;

final readonly class KeyRevoked implements DomainEvent
{
    public function __construct(
        public EventId $id,
        public ProductKeyId $keyId,
        public ProductId $productId,
        public ?OrderId $orderId,
        public int $version,
        public \DateTimeImmutable $occurredOn,
    ) {}

    public function eventId(): EventId
    {
        return $this->id;
    }

    public function aggregateId(): string
    {
        return $this->keyId->value;
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
