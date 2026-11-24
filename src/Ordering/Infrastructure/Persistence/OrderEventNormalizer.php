<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence;

use App\Ordering\Domain\Event\OrderCompensationStarted;
use App\Ordering\Domain\Event\OrderCompleted;
use App\Ordering\Domain\Event\OrderEvent;
use App\Ordering\Domain\Event\OrderFailed;
use App\Ordering\Domain\Event\OrderKeyReserved;
use App\Ordering\Domain\Event\OrderNeedsManualReview;
use App\Ordering\Domain\Event\OrderPaid;
use App\Ordering\Domain\Event\OrderPlaced;
use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Infrastructure\Outbox\EventNormalizer;
use App\Shared\Infrastructure\Outbox\NormalizedEvent;

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
}
