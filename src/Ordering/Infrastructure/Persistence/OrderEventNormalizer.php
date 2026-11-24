<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence;

use App\Ordering\Domain\Event\OrderCancellationRequested;
use App\Ordering\Domain\Event\OrderCancelled;
use App\Ordering\Domain\Event\OrderCompensationStarted;
use App\Ordering\Domain\Event\OrderCompleted;
use App\Ordering\Domain\Event\OrderEvent;
use App\Ordering\Domain\Event\OrderFailed;
use App\Ordering\Domain\Event\OrderKeyReserved;
use App\Ordering\Domain\Event\OrderNeedsManualReview;
use App\Ordering\Domain\Event\OrderPaid;
use App\Ordering\Domain\Event\OrderPlaced;
use App\Ordering\Domain\Event\OrderRefundStarted;
use App\Ordering\Domain\Event\OrderRefunded;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Domain\Event\EventId;
use App\Shared\Domain\FailureReason;
use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\PaymentId;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Identifier\ProductKeyId;
use App\Shared\Domain\Identifier\ReservationId;
use App\Shared\Domain\Money;
use App\Shared\Infrastructure\Outbox\EventNormalizer;
use App\Shared\Infrastructure\Outbox\NormalizedEvent;
use App\Shared\Infrastructure\Outbox\OutboxMessage;
use App\Shared\Infrastructure\Persistence\DbTimestamp;
use App\Shared\Infrastructure\Persistence\Row;

final readonly class OrderEventNormalizer implements EventNormalizer
{
    public function supports(DomainEvent $event): bool
    {
        return $event instanceof OrderEvent;
    }

    public function normalize(DomainEvent $event): NormalizedEvent
    {
        if (!$event instanceof OrderEvent) {
            throw new \LogicException(sprintf('%s is not an order event.', $event::class));
        }

        $payload = match (true) {
            $event instanceof OrderPlaced => [
                'customer_id' => $event->customerId->value,
                'product_id' => $event->productId->value,
                'price_amount' => $event->price->amount,
                'price_currency' => $event->price->currency->value,
            ],
            $event instanceof OrderKeyReserved => ['reservation_id' => $event->reservationId->value],
            $event instanceof OrderPaid => ['payment_id' => $event->paymentId->value],
            // Only the masked code travels (INV-17).
            $event instanceof OrderCompleted => ['key_id' => $event->keyId->value, 'key_masked' => $event->keyMasked],
            $event instanceof OrderCompensationStarted, $event instanceof OrderFailed => ['reason' => $event->reason->value],
            $event instanceof OrderNeedsManualReview => ['reason' => $event->reason->value, 'step' => $event->step],
            default => [],
        };

        return new NormalizedEvent('order', (new \ReflectionClass($event))->getShortName(), $payload);
    }

    public function supportsMessage(OutboxMessage $message): bool
    {
        return $message->aggregateType === 'order';
    }

    public function denormalize(OutboxMessage $message): DomainEvent
    {
        $payload = new Row($message->payload);
        $id = EventId::fromString($message->messageId);
        $orderId = OrderId::fromString($message->aggregateId);
        $version = $message->aggregateVersion;
        $at = DbTimestamp::parse($message->occurredAt);

        return match ($message->eventType) {
            'OrderPlaced' => new OrderPlaced(
                $id,
                $orderId,
                $version,
                $at,
                CustomerId::fromString($payload->string('customer_id')),
                ProductId::fromString($payload->string('product_id')),
                Money::of($payload->int('price_amount'), Currency::fromCode($payload->string('price_currency'))),
            ),
            'OrderKeyReserved' => new OrderKeyReserved($id, $orderId, $version, $at, ReservationId::fromString($payload->string('reservation_id'))),
            'OrderPaid' => new OrderPaid($id, $orderId, $version, $at, PaymentId::fromString($payload->string('payment_id'))),
            'OrderCompleted' => new OrderCompleted($id, $orderId, $version, $at, ProductKeyId::fromString($payload->string('key_id')), $payload->string('key_masked')),
            'OrderCompensationStarted' => new OrderCompensationStarted($id, $orderId, $version, $at, FailureReason::from($payload->string('reason'))),
            'OrderFailed' => new OrderFailed($id, $orderId, $version, $at, FailureReason::from($payload->string('reason'))),
            'OrderNeedsManualReview' => new OrderNeedsManualReview($id, $orderId, $version, $at, FailureReason::from($payload->string('reason')), $payload->string('step')),
            'OrderCancellationRequested' => new OrderCancellationRequested($id, $orderId, $version, $at),
            'OrderCancelled' => new OrderCancelled($id, $orderId, $version, $at),
            'OrderRefundStarted' => new OrderRefundStarted($id, $orderId, $version, $at),
            'OrderRefunded' => new OrderRefunded($id, $orderId, $version, $at),
            default => throw new \UnexpectedValueException(sprintf('Unknown order event "%s".', $message->eventType)),
        };
    }
}
