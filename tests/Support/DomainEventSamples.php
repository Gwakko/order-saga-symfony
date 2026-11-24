<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Inventory\Domain\Event\KeyReleased;
use App\Inventory\Domain\Event\KeyReserved;
use App\Inventory\Domain\Event\KeyRevoked;
use App\Inventory\Domain\Event\KeySold;
use App\Inventory\Domain\Event\ReservationExpired;
use App\Ordering\Domain\Event\OrderCancellationRequested;
use App\Ordering\Domain\Event\OrderCancelled;
use App\Ordering\Domain\Event\OrderCompensationStarted;
use App\Ordering\Domain\Event\OrderCompleted;
use App\Ordering\Domain\Event\OrderFailed;
use App\Ordering\Domain\Event\OrderKeyReserved;
use App\Ordering\Domain\Event\OrderNeedsManualReview;
use App\Ordering\Domain\Event\OrderPaid;
use App\Ordering\Domain\Event\OrderPlaced;
use App\Ordering\Domain\Event\OrderRefunded;
use App\Ordering\Domain\Event\OrderRefundStarted;
use App\Payment\Domain\Event\OrphanChargeDetected;
use App\Payment\Domain\Event\PaymentCapturedWithMismatch;
use App\Payment\Domain\Event\PaymentDeclined;
use App\Payment\Domain\Event\PaymentRefunded;
use App\Payment\Domain\Event\PaymentSucceeded;
use App\Payment\Domain\MismatchKind;
use App\Payment\Domain\MismatchReason;
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
use App\Shared\Domain\KeyCode;
use App\Shared\Domain\Money;

/**
 * One instance of every concrete domain event, with distinct, non-trivial values (microseconds in the time, a
 * non-default currency, a real key code behind the masked one).
 */
final class DomainEventSamples
{
    public const string SECRET_KEY = 'ZXCV-QWER-7391';

    /**
     * @return array<string, DomainEvent> keyed by `<event class>#<aggregate version>`
     */
    public static function all(): array
    {
        $at = new \DateTimeImmutable('2026-05-01T10:00:00.123456Z');
        $order = OrderId::fromString('0190a5c4-1000-7000-8000-0000000000a1');
        $customer = CustomerId::fromString('0190a5c4-1000-7000-8000-0000000000c1');
        $product = ProductId::fromString('0190a5c4-1000-7000-8000-0000000000b1');
        $key = ProductKeyId::fromString('0190a5c4-1000-7000-8000-0000000000d1');
        $reservation = ReservationId::fromString('0190a5c4-1000-7000-8000-0000000000e1');
        $payment = PaymentId::fromString('0190a5c4-1000-7000-8000-0000000000f1');
        $price = Money::of(1999, Currency::PLN);
        $masked = (new KeyCode(self::SECRET_KEY))->masked();

        $n = 0;
        $id = static function (string $type, string $aggregate) use (&$n): EventId {
            return EventId::derive($aggregate, ++$n, $type);
        };

        $events = [
            new OrderPlaced($id('OrderPlaced', $order->value), $order, 1, $at, $customer, $product, $price),
            new OrderKeyReserved($id('OrderKeyReserved', $order->value), $order, 2, $at->modify('+1 second'), $reservation),
            new OrderPaid($id('OrderPaid', $order->value), $order, 3, $at->modify('+2 seconds'), $payment),
            new OrderCompleted($id('OrderCompleted', $order->value), $order, 4, $at->modify('+3 seconds'), $key, $masked),
            new OrderFailed($id('OrderFailed', $order->value), $order, 5, $at->modify('+4 seconds'), FailureReason::PaymentDeclined),
            new OrderCompensationStarted($id('OrderCompensationStarted', $order->value), $order, 6, $at->modify('+5 seconds'), FailureReason::ReservationExpired),
            new OrderNeedsManualReview($id('OrderNeedsManualReview', $order->value), $order, 7, $at->modify('+6 seconds'), FailureReason::CompensationFailed, 'release_reservation'),
            new OrderCancellationRequested($id('OrderCancellationRequested', $order->value), $order, 8, $at->modify('+7 seconds')),
            new OrderCancelled($id('OrderCancelled', $order->value), $order, 9, $at->modify('+8 seconds')),
            new OrderRefundStarted($id('OrderRefundStarted', $order->value), $order, 10, $at->modify('+9 seconds')),
            new OrderRefunded($id('OrderRefunded', $order->value), $order, 11, $at->modify('+10 seconds')),

            new KeyReserved($id('KeyReserved', $reservation->value), $reservation, $key, $product, $order, 1, $at),
            new KeySold($id('KeySold', $reservation->value), $reservation, $key, $product, $order, 2, $at->modify('+1 second')),
            new KeyReleased($id('KeyReleased', $reservation->value), $reservation, $key, $product, $order, 3, $at->modify('+2 seconds')),
            new ReservationExpired($id('ReservationExpired', $reservation->value), $reservation, $key, $product, $order, 4, $at->modify('+3 seconds')),
            new KeyRevoked($id('KeyRevoked', $key->value), $key, $product, $order, 5, $at->modify('+4 seconds')),
            new KeyRevoked($id('KeyRevoked', $key->value), $key, $product, null, 6, $at->modify('+5 seconds')),

            new PaymentSucceeded($id('PaymentSucceeded', $payment->value), $payment, $order, $price, 1, $at),
            new PaymentDeclined($id('PaymentDeclined', $payment->value), $payment, $order, $price, 2, $at->modify('+1 second')),
            new PaymentRefunded($id('PaymentRefunded', $payment->value), $payment, $order, $price, 3, $at->modify('+2 seconds')),
            new OrphanChargeDetected($id('OrphanChargeDetected', $payment->value), $payment, $order, $price, 4, $at->modify('+3 seconds')),
            new PaymentCapturedWithMismatch(
                $id('PaymentCapturedWithMismatch', $payment->value),
                $payment,
                $order,
                $price,
                5,
                $at->modify('+4 seconds'),
                'ch_9f8e7d',
                new MismatchReason(MismatchKind::Currency, Money::of(1999, Currency::USD)),
            ),
            new PaymentCapturedWithMismatch(
                $id('PaymentCapturedWithMismatch', $payment->value),
                $payment,
                $order,
                $price,
                6,
                $at->modify('+5 seconds'),
                'ch_9f8e7e',
                new MismatchReason(MismatchKind::Amount, Money::of(2000, Currency::PLN)),
            ),
        ];

        $byClass = [];

        foreach ($events as $event) {
            $byClass[$event::class . '#' . $event->aggregateVersion()] = $event;
        }

        return $byClass;
    }

    /**
     * @return list<class-string<DomainEvent>> every concrete event class under `src/*\/Domain/Event`
     */
    public static function concreteEventClasses(): array
    {
        $classes = [];

        $files = glob(dirname(__DIR__, 2) . '/src/*/Domain/Event/*.php');

        foreach ($files === false ? [] : $files as $file) {
            $module = basename(dirname($file, 3));
            $class = sprintf('App\\%s\\Domain\\Event\\%s', $module, basename($file, '.php'));

            if (class_exists($class) && is_subclass_of($class, DomainEvent::class) && (new \ReflectionClass($class))->isInstantiable()) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }
}
