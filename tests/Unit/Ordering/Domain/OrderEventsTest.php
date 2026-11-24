<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Domain;

use App\Ordering\Domain\Event\OrderCancelled;
use App\Ordering\Domain\Event\OrderCancellationRequested;
use App\Ordering\Domain\Event\OrderCompensationStarted;
use App\Ordering\Domain\Event\OrderCompleted;
use App\Ordering\Domain\Event\OrderEvent;
use App\Ordering\Domain\Event\OrderFailed;
use App\Ordering\Domain\Event\OrderKeyReserved;
use App\Ordering\Domain\Event\OrderNeedsManualReview;
use App\Ordering\Domain\Event\OrderPaid;
use App\Ordering\Domain\Event\OrderPlaced;
use App\Ordering\Domain\Event\OrderRefunded;
use App\Ordering\Domain\Event\OrderRefundStarted;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Domain\FailureReason;
use App\Shared\Domain\Identifier\PaymentId;
use App\Shared\Domain\Identifier\ProductKeyId;
use App\Shared\Domain\Identifier\ReservationId;
use App\Shared\Domain\RefundWindow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * T-U-16: every transition adds exactly one event of the right type and bumps the version by one;
 * an idempotent repeat adds nothing.
 */
#[Group('T-U-16')]
final class OrderEventsTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(): Order, \Closure(Order): Order, class-string<OrderEvent>}>
     */
    public static function transitions(): iterable
    {
        $now = OrderFactory::later(500);

        yield 'markKeyReserved' => [
            OrderFactory::pending(...),
            fn(Order $o): Order => $o->markKeyReserved(ReservationId::fromString(OrderFactory::RESERVATION_ID), $now),
            OrderKeyReserved::class,
        ];
        yield 'markPaid' => [
            OrderFactory::keyReserved(...),
            fn(Order $o): Order => $o->markPaid(PaymentId::fromString(OrderFactory::PAYMENT_ID), $now),
            OrderPaid::class,
        ];
        yield 'complete' => [
            OrderFactory::paid(...),
            fn(Order $o): Order => $o->complete(ProductKeyId::fromString(OrderFactory::KEY_ID), OrderFactory::keyCode(), $now),
            OrderCompleted::class,
        ];
        yield 'requestCancellation' => [
            OrderFactory::paid(...),
            fn(Order $o): Order => $o->requestCancellation($now),
            OrderCancellationRequested::class,
        ];
        yield 'startCompensation' => [
            OrderFactory::paid(...),
            fn(Order $o): Order => $o->startCompensation(FailureReason::KeyRevoked, $now),
            OrderCompensationStarted::class,
        ];
        yield 'cancelBeforeStart' => [
            OrderFactory::pending(...),
            fn(Order $o): Order => $o->cancelBeforeStart($now),
            OrderCancelled::class,
        ];
        yield 'fail' => [
            OrderFactory::pending(...),
            fn(Order $o): Order => $o->fail(FailureReason::OutOfStock, $now),
            OrderFailed::class,
        ];
        yield 'finishCompensation -> Cancelled' => [
            fn(): Order => OrderFactory::compensating(true),
            fn(Order $o): Order => $o->finishCompensation($now),
            OrderCancelled::class,
        ];
        yield 'finishCompensation -> Failed' => [
            fn(): Order => OrderFactory::compensating(false),
            fn(Order $o): Order => $o->finishCompensation($now),
            OrderFailed::class,
        ];
        yield 'startRefund' => [
            OrderFactory::completed(...),
            fn(Order $o): Order => $o->startRefund($now, new RefundWindow()),
            OrderRefundStarted::class,
        ];
        yield 'finishRefund' => [
            OrderFactory::refunding(...),
            fn(Order $o): Order => $o->finishRefund($now),
            OrderRefunded::class,
        ];
        yield 'requireManualReview' => [
            OrderFactory::refunding(...),
            fn(Order $o): Order => $o->requireManualReview(FailureReason::CompensationFailed, 'RefundPayment', $now),
            OrderNeedsManualReview::class,
        ];
        yield 'resolveManually -> Cancelled' => [
            OrderFactory::manualReviewAfterCompensation(...),
            fn(Order $o): Order => $o->resolveManually(OrderStatus::Cancelled, $now),
            OrderCancelled::class,
        ];
        yield 'resolveManually -> Failed' => [
            OrderFactory::manualReviewAfterCompensation(...),
            fn(Order $o): Order => $o->resolveManually(OrderStatus::Failed, $now),
            OrderFailed::class,
        ];
        yield 'resolveManually -> Refunded' => [
            OrderFactory::manualReviewAfterRefund(...),
            fn(Order $o): Order => $o->resolveManually(OrderStatus::Refunded, $now),
            OrderRefunded::class,
        ];
    }

    /**
     * @param \Closure(): Order $given
     * @param \Closure(Order): Order $when
     * @param class-string<OrderEvent> $eventClass
     */
    #[DataProvider('transitions')]
    public function testExactlyOneEventAndVersionPlusOne(\Closure $given, \Closure $when, string $eventClass): void
    {
        $before = $given();
        $after = $when($before);

        self::assertSame($before->version + 1, $after->version);
        self::assertCount(count($before->pendingEvents) + 1, $after->pendingEvents);

        $event = OrderFactory::lastEvent($after);
        self::assertInstanceOf($eventClass, $event);
        self::assertSame($after->version, $event->aggregateVersion());
        self::assertSame(OrderFactory::ORDER_ID, $event->aggregateId());
        self::assertEquals($after->updatedAt, $event->occurredAt());
    }

    public function testPlaceRaisesOrderPlacedAtVersionOne(): void
    {
        $order = OrderFactory::pending();

        self::assertCount(1, $order->pendingEvents);
        self::assertInstanceOf(OrderPlaced::class, $order->pendingEvents[0]);
        self::assertSame(1, $order->version);
    }

    public function testIdempotentRepeatsAddNoEventAndKeepVersion(): void
    {
        $now = OrderFactory::later(500);
        $reserved = OrderFactory::keyReserved();
        $paid = OrderFactory::paid();
        $completed = OrderFactory::completed();
        $compensating = OrderFactory::compensating();
        $requested = OrderFactory::pending()->requestCancellation($now);
        $cancelledViaCompensation = OrderFactory::compensating(true)->finishCompensation($now);

        $repeats = [
            [$reserved, $reserved->markKeyReserved(ReservationId::fromString(OrderFactory::RESERVATION_ID), $now)],
            [$paid, $paid->markPaid(PaymentId::fromString(OrderFactory::PAYMENT_ID), $now)],
            [$completed, $completed->complete(ProductKeyId::fromString(OrderFactory::KEY_ID), OrderFactory::keyCode(), $now)],
            [$compensating, $compensating->startCompensation(FailureReason::PaymentDeclined, $now)],
            [$requested, $requested->requestCancellation($now)],
            [$cancelledViaCompensation, $cancelledViaCompensation->finishCompensation($now)],
            [$compensating, $compensating->requestCancellation($now)],
        ];

        foreach ($repeats as [$before, $after]) {
            self::assertSame($before->version, $after->version);
            self::assertCount(count($before->pendingEvents), $after->pendingEvents);
        }
    }

    public function testEventIdsAreUniquePerTransitionAndStable(): void
    {
        $a = OrderFactory::completed();
        $b = OrderFactory::completed();
        $ids = array_map(static fn(OrderEvent $e): string => $e->eventId()->value, $a->pendingEvents);

        self::assertCount(count($ids), array_unique($ids));
        self::assertSame($ids, array_map(static fn(OrderEvent $e): string => $e->eventId()->value, $b->pendingEvents));
    }
}
