<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Domain;

use App\Ordering\Domain\Exception\IllegalOrderTransition;
use App\Ordering\Domain\Exception\OrderInvariantViolated;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Domain\DomainErrorCode;
use App\Shared\Domain\FailureReason;
use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\PaymentId;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Identifier\ProductKeyId;
use App\Shared\Domain\Identifier\ReservationId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class OrderTest extends TestCase
{
    /**
     * EC-13: Paid with P1, markPaid(P2) must be rejected (INV-06, INV-03).
     */
    #[Group('T-U-05')]
    public function testMarkPaidWithOtherPaymentRejected(): void
    {
        $paid = OrderFactory::paid();

        try {
            $paid->markPaid(PaymentId::fromString(OrderFactory::OTHER_PAYMENT_ID), OrderFactory::later(99));
            self::fail('Expected IllegalOrderTransition');
        } catch (IllegalOrderTransition $e) {
            self::assertSame(DomainErrorCode::IllegalOrderTransition, $e->code());
            self::assertSame(OrderStatus::Paid, $e->from);
            self::assertSame(OrderStatus::Paid, $e->to);
        }

        self::assertSame(OrderFactory::PAYMENT_ID, $paid->paymentId?->value);
    }

    public function testIdempotentRepeatsReturnSameInstance(): void
    {
        $keyReserved = OrderFactory::keyReserved();
        $paid = OrderFactory::paid();
        $completed = OrderFactory::completed();
        $compensating = OrderFactory::compensating();

        self::assertSame($keyReserved, $keyReserved->markKeyReserved(ReservationId::fromString(OrderFactory::RESERVATION_ID), OrderFactory::later(99)));
        self::assertSame($paid, $paid->markPaid(PaymentId::fromString(OrderFactory::PAYMENT_ID), OrderFactory::later(99)));
        self::assertSame($completed, $completed->complete(ProductKeyId::fromString(OrderFactory::KEY_ID), OrderFactory::keyCode(), OrderFactory::later(99)));
        self::assertSame($compensating, $compensating->startCompensation(FailureReason::KeyRevoked, OrderFactory::later(99)));
        self::assertSame(FailureReason::PaymentDeclined, $compensating->startCompensation(FailureReason::KeyRevoked, OrderFactory::later(99))->compensationReason);
    }

    public function testFinishCompensationIsIdempotentInTargetStatus(): void
    {
        $cancelled = OrderFactory::compensating(true)->finishCompensation(OrderFactory::later(60));
        $failed = OrderFactory::compensating(false)->finishCompensation(OrderFactory::later(60));

        self::assertSame($cancelled, $cancelled->finishCompensation(OrderFactory::later(70)));
        self::assertSame($failed, $failed->finishCompensation(OrderFactory::later(70)));
    }

    public function testOtherReservationOrKeyRejected(): void
    {
        $keyReserved = OrderFactory::keyReserved();
        $completed = OrderFactory::completed();

        try {
            $keyReserved->markKeyReserved(ReservationId::fromString(OrderFactory::OTHER_RESERVATION_ID), OrderFactory::later(99));
            self::fail('Expected IllegalOrderTransition');
        } catch (IllegalOrderTransition) {
            // expected
        }

        $this->expectException(IllegalOrderTransition::class);
        $completed->complete(ProductKeyId::fromString(OrderFactory::OTHER_KEY_ID), OrderFactory::keyCode(), OrderFactory::later(99));
    }

    public function testTransitionsDoNotMutateTheOriginal(): void
    {
        $pending = OrderFactory::pending();
        $paidPath = $pending->markKeyReserved(ReservationId::fromString(OrderFactory::RESERVATION_ID), OrderFactory::later(10));

        self::assertSame(OrderStatus::Pending, $pending->status);
        self::assertNull($pending->reservationId);
        self::assertSame(1, $pending->version);
        self::assertCount(1, $pending->pendingEvents);
        self::assertSame(2, $paidPath->version);
        self::assertCount(2, $paidPath->pendingEvents);
    }

    /**
     * INV-07/INV-08: a completed order carries reservation, payment and key; the key can only be delivered from Paid.
     */
    public function testCompletedOrderCarriesAllReferences(): void
    {
        $completed = OrderFactory::completed();

        self::assertSame(OrderStatus::Completed, $completed->status);
        self::assertNotNull($completed->reservationId);
        self::assertNotNull($completed->paymentId);
        self::assertSame(OrderFactory::KEY_ID, $completed->deliveredKeyId?->value);
        self::assertEquals(OrderFactory::later(30), $completed->completedAt);
    }

    public function testCannotCompleteWithoutPayment(): void
    {
        $this->expectException(IllegalOrderTransition::class);
        OrderFactory::keyReserved()->complete(ProductKeyId::fromString(OrderFactory::KEY_ID), OrderFactory::keyCode(), OrderFactory::later(30));
    }

    public function testCompletedEventNeverContainsFullKeyCode(): void
    {
        $event = OrderFactory::completed()->pendingEvents[3];

        self::assertInstanceOf(\App\Ordering\Domain\Event\OrderCompleted::class, $event);
        self::assertSame('XXXX-...-AB12', $event->keyMasked);
        self::assertStringNotContainsString('ABCDE', serialize($event));
    }

    public function testFailureReasonOnlyInFailedCancelledManualReview(): void
    {
        foreach (OrderStatus::cases() as $status) {
            foreach (OrderFactory::variants($status) as $order) {
                $expected = in_array($status, [OrderStatus::Cancelled, OrderStatus::Failed, OrderStatus::ManualReview], true);
                self::assertSame($expected, $order->failureReason !== null, $status->value);
                if ($status === OrderStatus::Cancelled) {
                    self::assertSame(FailureReason::CancelledByCustomer, $order->failureReason);
                }
            }
        }
    }

    public function testVersionGrowsByOnePerTransition(): void
    {
        $order = OrderFactory::pending();
        self::assertSame(1, $order->version);

        $order = $order->markKeyReserved(ReservationId::fromString(OrderFactory::RESERVATION_ID), OrderFactory::later(1));
        self::assertSame(2, $order->version);
        $order = $order->markPaid(PaymentId::fromString(OrderFactory::PAYMENT_ID), OrderFactory::later(2));
        self::assertSame(3, $order->version);
        $order = $order->complete(ProductKeyId::fromString(OrderFactory::KEY_ID), OrderFactory::keyCode(), OrderFactory::later(3));
        self::assertSame(4, $order->version);
        self::assertEquals(OrderFactory::later(3), $order->updatedAt);
        self::assertEquals(OrderFactory::t0(), $order->createdAt);
    }

    /**
     * @return iterable<string, array{OrderStatus, ?FailureReason}>
     */
    public static function brokenStates(): iterable
    {
        yield 'failed without reason' => [OrderStatus::Failed, null];
        yield 'pending with reason' => [OrderStatus::Pending, FailureReason::OutOfStock];
        yield 'cancelled with wrong reason' => [OrderStatus::Cancelled, FailureReason::OutOfStock];
        yield 'completed without references' => [OrderStatus::Completed, null];
    }

    #[DataProvider('brokenStates')]
    public function testReconstituteRejectsBrokenInvariants(OrderStatus $status, ?FailureReason $reason): void
    {
        $this->expectException(OrderInvariantViolated::class);

        $this->reconstitute($status, $reason, 1);
    }

    public function testReconstituteRejectsVersionBelowOne(): void
    {
        $this->expectException(OrderInvariantViolated::class);

        $this->reconstitute(OrderStatus::Pending, null, 0);
    }

    public function testReconstituteHasNoPendingEvents(): void
    {
        self::assertSame([], $this->reconstitute(OrderStatus::Pending, null, 3)->pendingEvents);
    }

    private function reconstitute(OrderStatus $status, ?FailureReason $reason, int $version): Order
    {
        return Order::reconstitute(
            OrderId::fromString(OrderFactory::ORDER_ID),
            CustomerId::fromString(OrderFactory::CUSTOMER_ID),
            ProductId::fromString(OrderFactory::PRODUCT_ID),
            OrderFactory::price(),
            $status,
            null,
            null,
            null,
            $reason,
            null,
            false,
            OrderFactory::t0(),
            OrderFactory::t0(),
            null,
            $version,
        );
    }
}
