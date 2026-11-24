<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence;

use App\Inventory\Domain\Event\KeyRevoked;
use App\Inventory\Domain\Event\ReservationEvent;
use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Infrastructure\Outbox\EventNormalizer;
use App\Shared\Infrastructure\Outbox\NormalizedEvent;

final readonly class InventoryEventNormalizer implements EventNormalizer
{
    public function supports(DomainEvent $event): bool
    {
        return $event instanceof ReservationEvent || $event instanceof KeyRevoked;
    }

    public function normalize(DomainEvent $event): NormalizedEvent
    {
        return match (true) {
            $event instanceof ReservationEvent => new NormalizedEvent(
                'reservation',
                (new \ReflectionClass($event))->getShortName(),
                [
                    'reservation_id' => $event->reservationId->value,
                    'key_id' => $event->keyId->value,
                    'product_id' => $event->productId->value,
                    'order_id' => $event->orderId->value,
                ],
            ),
            $event instanceof KeyRevoked => new NormalizedEvent(
                'key',
                'KeyRevoked',
                ['key_id' => $event->keyId->value, 'product_id' => $event->productId->value, 'order_id' => $event->orderId?->value],
            ),
            default => throw new \LogicException(sprintf('%s is not an inventory event.', $event::class)),
        };
    }
}
