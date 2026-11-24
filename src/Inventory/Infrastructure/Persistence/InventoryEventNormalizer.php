<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence;

use App\Inventory\Domain\Event\KeyReleased;
use App\Inventory\Domain\Event\KeyReserved;
use App\Inventory\Domain\Event\KeyRevoked;
use App\Inventory\Domain\Event\KeySold;
use App\Inventory\Domain\Event\ReservationEvent;
use App\Inventory\Domain\Event\ReservationExpired;
use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Domain\Event\EventId;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Identifier\ProductKeyId;
use App\Shared\Domain\Identifier\ReservationId;
use App\Shared\Infrastructure\Outbox\EventNormalizer;
use App\Shared\Infrastructure\Outbox\NormalizedEvent;
use App\Shared\Infrastructure\Outbox\OutboxMessage;
use App\Shared\Infrastructure\Persistence\DbTimestamp;
use App\Shared\Infrastructure\Persistence\Row;

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

    public function supportsMessage(OutboxMessage $message): bool
    {
        return $message->aggregateType === 'reservation' || $message->aggregateType === 'key';
    }

    public function denormalize(OutboxMessage $message): DomainEvent
    {
        $payload = new Row($message->payload);
        $id = EventId::fromString($message->messageId);
        $version = $message->aggregateVersion;
        $at = DbTimestamp::parse($message->occurredAt);

        if ($message->eventType === 'KeyRevoked') {
            $orderId = $payload->nullableString('order_id');

            return new KeyRevoked(
                $id,
                ProductKeyId::fromString($message->aggregateId),
                ProductId::fromString($payload->string('product_id')),
                $orderId === null ? null : OrderId::fromString($orderId),
                $version,
                $at,
            );
        }

        $reservationId = ReservationId::fromString($message->aggregateId);
        $keyId = ProductKeyId::fromString($payload->string('key_id'));
        $productId = ProductId::fromString($payload->string('product_id'));
        $orderId = OrderId::fromString($payload->string('order_id'));

        return match ($message->eventType) {
            'KeyReserved' => new KeyReserved($id, $reservationId, $keyId, $productId, $orderId, $version, $at),
            'KeySold' => new KeySold($id, $reservationId, $keyId, $productId, $orderId, $version, $at),
            'KeyReleased' => new KeyReleased($id, $reservationId, $keyId, $productId, $orderId, $version, $at),
            'ReservationExpired' => new ReservationExpired($id, $reservationId, $keyId, $productId, $orderId, $version, $at),
            default => throw new \UnexpectedValueException(sprintf('Unknown inventory event "%s".', $message->eventType)),
        };
    }
}
