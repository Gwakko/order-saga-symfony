<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Infrastructure\Persistence\DbTimestamp;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Appends events to `outbox_messages` on the caller's connection. Repositories call it inside the transaction that
 * writes the aggregate, which is what makes state and events atomic (INV-10).
 */
final readonly class OutboxWriter
{
    /**
     * @param iterable<EventNormalizer> $normalizers
     */
    public function __construct(
        private Connection $connection,
        #[AutowireIterator('app.outbox.event_normalizer')]
        private iterable $normalizers,
    ) {}

    /**
     * @param list<DomainEvent> $events
     */
    public function append(array $events): void
    {
        foreach ($events as $event) {
            $normalized = $this->normalize($event);

            $this->connection->executeStatement(
                'INSERT INTO outbox_messages (id, aggregate_type, aggregate_id, aggregate_version, event_type, payload, occurred_at)
                 VALUES (:id, :aggregate_type, :aggregate_id, :aggregate_version, :event_type, CAST(:payload AS jsonb), :occurred_at)',
                [
                    'id' => $event->eventId()->value,
                    'aggregate_type' => $normalized->aggregateType,
                    'aggregate_id' => $event->aggregateId(),
                    'aggregate_version' => $event->aggregateVersion(),
                    'event_type' => $normalized->eventType,
                    'payload' => json_encode($normalized->payload === [] ? new \stdClass() : $normalized->payload, JSON_THROW_ON_ERROR),
                    'occurred_at' => DbTimestamp::format($event->occurredAt()),
                ],
            );
        }
    }

    private function normalize(DomainEvent $event): NormalizedEvent
    {
        foreach ($this->normalizers as $normalizer) {
            if ($normalizer->supports($event)) {
                return $normalizer->normalize($event);
            }
        }

        throw new \LogicException(sprintf('No outbox normalizer for %s.', $event::class));
    }
}
