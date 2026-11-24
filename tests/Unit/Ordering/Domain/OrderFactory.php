<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Domain;

use App\Ordering\Domain\Event\OrderEvent;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Domain\Currency;
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
 * Builds orders in every status through real transitions only (never by poking state).
 */
final class OrderFactory
{
    public const ORDER_ID = '0190a5c4-0000-7000-8000-000000000001';
    public const CUSTOMER_ID = '0190a5c4-0000-7000-8000-000000000002';
    public const PRODUCT_ID = '0190a5c4-0000-7000-8000-000000000003';
    public const RESERVATION_ID = '0190a5c4-0000-7000-8000-000000000004';
    public const PAYMENT_ID = '0190a5c4-0000-7000-8000-000000000005';
    public const KEY_ID = '0190a5c4-0000-7000-8000-000000000006';
    public const OTHER_RESERVATION_ID = '0190a5c4-0000-7000-8000-0000000000a4';
    public const OTHER_PAYMENT_ID = '0190a5c4-0000-7000-8000-0000000000a5';
    public const OTHER_KEY_ID = '0190a5c4-0000-7000-8000-0000000000a6';

    public static function t0(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-05-01T10:00:00Z');
    }

    public static function later(int $seconds): \DateTimeImmutable
    {
        return self::t0()->modify(sprintf('+%d seconds', $seconds));
    }

    public static function price(): Money
    {
        return Money::positive(1999, Currency::EUR);
    }

    public static function keyCode(): KeyCode
    {
        return new KeyCode('ABCDE-FGHIJ-AB12');
    }

    public static function lastEvent(Order $order): OrderEvent
    {
        $events = $order->pendingEvents;
        $last = end($events);

        return $last instanceof OrderEvent ? $last : throw new \LogicException('Order has no pending events');
    }

    public static function pending(): Order
    {
        return Order::place(
            OrderId::fromString(self::ORDER_ID),
            CustomerId::fromString(self::CUSTOMER_ID),
            ProductId::fromString(self::PRODUCT_ID),
            self::price(),
            self::price(),
            self::t0(),
        );
    }

    public static function keyReserved(): Order
    {
        return self::pending()->markKeyReserved(ReservationId::fromString(self::RESERVATION_ID), self::later(10));
    }

    public static function paid(): Order
    {
        return self::keyReserved()->markPaid(PaymentId::fromString(self::PAYMENT_ID), self::later(20));
    }

    public static function completed(): Order
    {
        return self::paid()->complete(ProductKeyId::fromString(self::KEY_ID), self::keyCode(), self::later(30));
    }

    public static function compensating(bool $cancellationRequested = false): Order
    {
        $order = self::keyReserved();

        if ($cancellationRequested) {
            $order = $order->requestCancellation(self::later(15));
        }

        return $order->startCompensation($cancellationRequested ? FailureReason::CancelledByCustomer : FailureReason::PaymentDeclined, self::later(40));
    }

    public static function cancelled(): Order
    {
        return self::pending()->cancelBeforeStart(self::later(10));
    }

    public static function failed(): Order
    {
        return self::pending()->fail(FailureReason::OutOfStock, self::later(10));
    }

    public static function refunding(): Order
    {
        return self::completed()->startRefund(self::later(100), new \App\Shared\Domain\RefundWindow());
    }

    public static function refunded(): Order
    {
        return self::refunding()->finishRefund(self::later(110));
    }

    public static function manualReviewAfterCompensation(): Order
    {
        return self::compensating()->requireManualReview(FailureReason::CompensationFailed, 'ReleaseReservation', self::later(50));
    }

    public static function manualReviewAfterRefund(): Order
    {
        return self::refunding()->requireManualReview(FailureReason::CompensationFailed, 'RefundPayment', self::later(120));
    }

    /**
     * Every distinct way to reach a status, so guards that depend on history are exercised.
     *
     * @return list<Order>
     */
    public static function variants(OrderStatus $status): array
    {
        return match ($status) {
            OrderStatus::Pending => [self::pending()],
            OrderStatus::KeyReserved => [self::keyReserved()],
            OrderStatus::Paid => [self::paid()],
            OrderStatus::Completed => [self::completed()],
            OrderStatus::Compensating => [self::compensating(false), self::compensating(true)],
            OrderStatus::Cancelled => [self::cancelled(), self::compensating(true)->finishCompensation(self::later(60))],
            OrderStatus::Failed => [self::failed(), self::compensating(false)->finishCompensation(self::later(60))],
            OrderStatus::Refunding => [self::refunding()],
            OrderStatus::Refunded => [self::refunded()],
            OrderStatus::ManualReview => [self::manualReviewAfterCompensation(), self::manualReviewAfterRefund()],
        };
    }
}
