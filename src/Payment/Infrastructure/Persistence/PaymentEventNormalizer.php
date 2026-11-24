<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Persistence;

use App\Payment\Domain\Event\PaymentCapturedWithMismatch;
use App\Payment\Domain\Event\PaymentEvent;
use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Infrastructure\Outbox\EventNormalizer;
use App\Shared\Infrastructure\Outbox\NormalizedEvent;

final readonly class PaymentEventNormalizer implements EventNormalizer
{
    public function supports(DomainEvent $event): bool
    {
        return $event instanceof PaymentEvent;
    }

    public function normalize(DomainEvent $event): NormalizedEvent
    {
        if (!$event instanceof PaymentEvent) {
            throw new \LogicException(sprintf('%s is not a payment event.', $event::class));
        }

        $payload = [
            'payment_id' => $event->paymentId->value,
            'order_id' => $event->orderId->value,
            'amount' => $event->amount->amount,
            'currency' => $event->amount->currency->value,
        ];

        if ($event instanceof PaymentCapturedWithMismatch) {
            $payload += [
                'charge_id' => $event->chargeId,
                'mismatch_kind' => $event->reason->kind->value,
                'charged_amount' => $event->reason->charged->amount,
                'charged_currency' => $event->reason->charged->currency->value,
            ];
        }

        return new NormalizedEvent('payment', (new \ReflectionClass($event))->getShortName(), $payload);
    }
}
