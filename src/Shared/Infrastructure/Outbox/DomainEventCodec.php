<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Infrastructure\Persistence\DbTimestamp;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Domain event <-> the message the outbox stores and Messenger carries (as JSON). Consumers (the projection, the saga
 * starter) turn a received message back into the event they react to; the mapping lives in the per-module
 * `EventNormalizer`s, so the domain stays free of serialisation concerns.
 */
final readonly class DomainEventCodec
{
    /**
     * @param iterable<EventNormalizer> $normalizers
     */
    public function __construct(
        #[AutowireIterator('app.outbox.event_normalizer')]
        private iterable $normalizers,
    ) {}

    public function toMessage(DomainEvent $event): OutboxMessage
    {
        foreach ($this->normalizers as $normalizer) {
            if ($normalizer->supports($event)) {
                $normalized = $normalizer->normalize($event);

                return new OutboxMessage(
                    $event->eventId()->value,
                    $normalized->aggregateType,
                    $event->aggregateId(),
                    $event->aggregateVersion(),
                    $normalized->eventType,
                    $normalized->payload,
                    DbTimestamp::format($event->occurredAt()),
                );
            }
        }

        throw new \LogicException(sprintf('No outbox normalizer for %s.', $event::class));
    }

    public function toEvent(OutboxMessage $message): DomainEvent
    {
        foreach ($this->normalizers as $normalizer) {
            if ($normalizer->supportsMessage($message)) {
                return $normalizer->denormalize($message);
            }
        }

        throw new \UnexpectedValueException(sprintf('No outbox normalizer for aggregate type "%s".', $message->aggregateType));
    }
}
