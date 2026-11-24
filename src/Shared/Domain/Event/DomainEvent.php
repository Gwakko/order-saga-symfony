<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

interface DomainEvent
{
    public function eventId(): EventId;

    public function aggregateId(): string;

    public function aggregateVersion(): int;

    public function occurredAt(): \DateTimeImmutable;
}
