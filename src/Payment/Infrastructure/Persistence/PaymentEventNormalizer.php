<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Persistence;

use App\Payment\Domain\Event\OrphanChargeDetected;
use App\Payment\Domain\Event\PaymentCapturedWithMismatch;
use App\Payment\Domain\Event\PaymentDeclined;
use App\Payment\Domain\Event\PaymentEvent;
use App\Payment\Domain\Event\PaymentRefunded;
use App\Payment\Domain\Event\PaymentSucceeded;
use App\Payment\Domain\MismatchKind;
use App\Payment\Domain\MismatchReason;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Domain\Event\EventId;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\PaymentId;
use App\Shared\Domain\Money;
use App\Shared\Infrastructure\Outbox\EventNormalizer;
use App\Shared\Infrastructure\Outbox\NormalizedEvent;
use App\Shared\Infrastructure\Outbox\OutboxMessage;
use App\Shared\Infrastructure\Persistence\DbTimestamp;
use App\Shared\Infrastructure\Persistence\Row;

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

    public function supportsMessage(OutboxMessage $message): bool
    {
        return $message->aggregateType === 'payment';
    }

    public function denormalize(OutboxMessage $message): DomainEvent
    {
        $payload = new Row($message->payload);
        $id = EventId::fromString($message->messageId);
        $paymentId = PaymentId::fromString($message->aggregateId);
        $orderId = OrderId::fromString($payload->string('order_id'));
        $amount = Money::of($payload->int('amount'), Currency::fromCode($payload->string('currency')));
        $version = $message->aggregateVersion;
        $at = DbTimestamp::parse($message->occurredAt);

        return match ($message->eventType) {
            'PaymentSucceeded' => new PaymentSucceeded($id, $paymentId, $orderId, $amount, $version, $at),
            'PaymentDeclined' => new PaymentDeclined($id, $paymentId, $orderId, $amount, $version, $at),
            'PaymentRefunded' => new PaymentRefunded($id, $paymentId, $orderId, $amount, $version, $at),
            'OrphanChargeDetected' => new OrphanChargeDetected($id, $paymentId, $orderId, $amount, $version, $at),
            'PaymentCapturedWithMismatch' => new PaymentCapturedWithMismatch(
                $id,
                $paymentId,
                $orderId,
                $amount,
                $version,
                $at,
                $payload->string('charge_id'),
                new MismatchReason(
                    MismatchKind::from($payload->string('mismatch_kind')),
                    Money::of($payload->int('charged_amount'), Currency::fromCode($payload->string('charged_currency'))),
                ),
            ),
            default => throw new \UnexpectedValueException(sprintf('Unknown payment event "%s".', $message->eventType)),
        };
    }
}
