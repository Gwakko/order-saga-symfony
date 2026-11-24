<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Domain;

use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Domain\DomainError;
use App\Shared\Domain\FailureReason;
use App\Shared\Domain\Identifier\PaymentId;
use App\Shared\Domain\Identifier\ProductKeyId;
use App\Shared\Domain\Identifier\ReservationId;
use App\Shared\Domain\RefundWindow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * T-U-04: the whole 10x10 transition table of 01_domain.md section 3.2 (INV-03).
 */
#[Group('T-U-04')]
final class OrderTransitionTest extends TestCase
{
    /**
     * Transcribed by hand from the spec table (not derived from the code under test).
     *
     * @return array<string, list<OrderStatus>>
     */
    private static function allowed(): array
    {
        return [
            'pending' => [OrderStatus::KeyReserved, OrderStatus::Cancelled, OrderStatus::Failed],
            'key_reserved' => [OrderStatus::Paid, OrderStatus::Compensating],
            'paid' => [OrderStatus::Completed, OrderStatus::Compensating],
            'completed' => [OrderStatus::Refunding],
            'compensating' => [OrderStatus::Cancelled, OrderStatus::Failed, OrderStatus::ManualReview],
            'cancelled' => [],
            'failed' => [],
            'refunding' => [OrderStatus::Refunded, OrderStatus::ManualReview],
            'refunded' => [],
            'manual_review' => [OrderStatus::Cancelled, OrderStatus::Failed, OrderStatus::Refunded],
        ];
    }

    /**
     * @return iterable<string, array{OrderStatus, OrderStatus, bool}>
     */
    public static function allPairs(): iterable
    {
        foreach (OrderStatus::cases() as $from) {
            foreach (OrderStatus::cases() as $to) {
                yield $from->value . ' -> ' . $to->value => [$from, $to, in_array($to, self::allowed()[$from->value], true)];
            }
        }
    }

    #[DataProvider('allPairs')]
    public function testStatusTableMatchesSpec(OrderStatus $from, OrderStatus $to, bool $expected): void
    {
        self::assertSame($expected, $from->canTransitionTo($to));
    }

    /**
     * The aggregate must agree with the table: a pair is reachable by some aggregate method (from some history)
     * if and only if the spec allows it; every other attempt raises a typed domain error and never changes status.
     */
    #[DataProvider('allPairs')]
    public function testAggregateMethodsMatchSpec(OrderStatus $from, OrderStatus $to, bool $expected): void
    {
        $reached = false;

        foreach (OrderFactory::variants($from) as $order) {
            foreach (self::attemptsTowards($to) as $attempt) {
                try {
                    $result = $attempt($order);
                } catch (DomainError) {
                    continue;
                }

                if ($result->status === $to && $result->version === $order->version + 1) {
                    $reached = true;
                } elseif ($result !== $order) {
                    // The method led to a different status (e.g. finishCompensation -> Failed when asked for Cancelled): it must be a legal one.
                    self::assertTrue($order->status->canTransitionTo($result->status));
                    self::assertSame($order->version + 1, $result->version);
                }
            }
        }

        self::assertSame($expected, $reached, sprintf('%s -> %s', $from->value, $to->value));
    }

    /**
     * Every public transition method that can lead to $to, called with fresh identifiers (so idempotent repeats do not apply).
     *
     * @return list<\Closure(Order): Order>
     */
    private static function attemptsTowards(OrderStatus $to): array
    {
        $now = OrderFactory::later(1000);

        return match ($to) {
            OrderStatus::Pending => [],
            OrderStatus::KeyReserved => [
                fn(Order $o): Order => $o->markKeyReserved(ReservationId::fromString(OrderFactory::OTHER_RESERVATION_ID), $now),
            ],
            OrderStatus::Paid => [
                fn(Order $o): Order => $o->markPaid(PaymentId::fromString(OrderFactory::OTHER_PAYMENT_ID), $now),
            ],
            OrderStatus::Completed => [
                fn(Order $o): Order => $o->complete(ProductKeyId::fromString(OrderFactory::OTHER_KEY_ID), OrderFactory::keyCode(), $now),
            ],
            OrderStatus::Compensating => [
                fn(Order $o): Order => $o->startCompensation(FailureReason::PaymentDeclined, $now),
            ],
            OrderStatus::Cancelled => [
                fn(Order $o): Order => $o->cancelBeforeStart($now),
                fn(Order $o): Order => $o->finishCompensation($now),
                fn(Order $o): Order => $o->resolveManually(OrderStatus::Cancelled, $now),
            ],
            OrderStatus::Failed => [
                fn(Order $o): Order => $o->fail(FailureReason::OutOfStock, $now),
                fn(Order $o): Order => $o->finishCompensation($now),
                fn(Order $o): Order => $o->resolveManually(OrderStatus::Failed, $now),
            ],
            OrderStatus::Refunding => [
                fn(Order $o): Order => $o->startRefund($now, new RefundWindow()),
            ],
            OrderStatus::Refunded => [
                fn(Order $o): Order => $o->finishRefund($now),
                fn(Order $o): Order => $o->resolveManually(OrderStatus::Refunded, $now),
            ],
            OrderStatus::ManualReview => [
                fn(Order $o): Order => $o->requireManualReview(FailureReason::CompensationFailed, 'step', $now),
            ],
        };
    }

    public function testCompensatingGoesToCancelledOnlyIfCancellationWasRequested(): void
    {
        $cancelled = OrderFactory::compensating(true)->finishCompensation(OrderFactory::later(60));
        $failed = OrderFactory::compensating(false)->finishCompensation(OrderFactory::later(60));

        self::assertSame(OrderStatus::Cancelled, $cancelled->status);
        self::assertSame(FailureReason::CancelledByCustomer, $cancelled->failureReason);
        self::assertSame(OrderStatus::Failed, $failed->status);
        self::assertSame(FailureReason::PaymentDeclined, $failed->failureReason);
    }

    public function testManualReviewCannotBeRefundedIfNoKeyWasDelivered(): void
    {
        $order = OrderFactory::manualReviewAfterCompensation();

        $this->expectException(\App\Ordering\Domain\Exception\IllegalOrderTransition::class);
        $order->resolveManually(OrderStatus::Refunded, OrderFactory::later(70));
    }

    public function testManualReviewAfterRefundCanBeResolvedAsRefunded(): void
    {
        $resolved = OrderFactory::manualReviewAfterRefund()->resolveManually(OrderStatus::Refunded, OrderFactory::later(130));

        self::assertSame(OrderStatus::Refunded, $resolved->status);
        self::assertNull($resolved->failureReason);
    }

    public function testResolveManuallyRejectsTargetsOutsideTheTable(): void
    {
        $this->expectException(\App\Ordering\Domain\Exception\IllegalOrderTransition::class);
        OrderFactory::manualReviewAfterCompensation()->resolveManually(OrderStatus::Paid, OrderFactory::later(70));
    }

    /**
     * @return iterable<string, array{OrderStatus, bool, bool}>
     */
    public static function statusFlags(): iterable
    {
        yield 'pending' => [OrderStatus::Pending, false, true];
        yield 'key_reserved' => [OrderStatus::KeyReserved, false, true];
        yield 'paid' => [OrderStatus::Paid, false, true];
        yield 'completed' => [OrderStatus::Completed, false, false];
        yield 'compensating' => [OrderStatus::Compensating, false, false];
        yield 'cancelled' => [OrderStatus::Cancelled, true, false];
        yield 'failed' => [OrderStatus::Failed, true, false];
        yield 'refunding' => [OrderStatus::Refunding, false, false];
        yield 'refunded' => [OrderStatus::Refunded, true, false];
        yield 'manual_review' => [OrderStatus::ManualReview, false, false];
    }

    #[DataProvider('statusFlags')]
    public function testTerminalAndCancellableFlags(OrderStatus $status, bool $terminal, bool $cancellable): void
    {
        self::assertSame($terminal, $status->isTerminal());
        self::assertSame($cancellable, $status->isCancellable());
    }
}
