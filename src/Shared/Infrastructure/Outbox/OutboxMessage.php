<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

/**
 * The Messenger message the relay publishes for one outbox row. `messageId` is the event id, so consumers
 * deduplicate on it (inbox, INV-11).
 */
final readonly class OutboxMessage
{
    /**
     * @param array<string, mixed> $payload decoded JSON of the outbox row
     */
    public function __construct(
        public string $messageId,
        public string $aggregateType,
        public string $aggregateId,
        public int $aggregateVersion,
        public string $eventType,
        public array $payload,
        public string $occurredAt,
    ) {}
}
