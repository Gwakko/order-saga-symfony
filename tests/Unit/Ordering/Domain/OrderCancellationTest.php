<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Domain;

use App\Ordering\Domain\Event\OrderCancellationRequested;
use App\Ordering\Domain\Exception\OrderNotCancellable;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Domain\DomainErrorCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class OrderCancellationTest extends TestCase
{
    /**
     * EC-42.
     */
    #[Group('T-U-06')]
    public function testCompletedNotCancellable(): void
    {
        try {
            OrderFactory::completed()->requestCancellation(OrderFactory::later(99));
            self::fail('Expected OrderNotCancellable');
        } catch (OrderNotCancellable $e) {
            self::assertSame(DomainErrorCode::OrderNotCancellable, $e->code());
            self::assertSame(OrderStatus::Completed, $e->status);
        }
    }

    /**
     * EC-43: the second request is a no-op without a second event.
     */
    #[Group('T-U-07')]
    public function testRepeatedRequestIsIdempotent(): void
    {
        $first = OrderFactory::keyReserved()->requestCancellation(OrderFactory::later(50));
        $second = $first->requestCancellation(OrderFactory::later(60));

        self::assertTrue($first->cancellationRequested);
        self::assertSame($first, $second);
        self::assertCount(
            1,
            array_filter($second->pendingEvents, static fn($e): bool => $e instanceof OrderCancellationRequested),
        );
    }

    /**
     * @return iterable<string, array{OrderStatus}>
     */
    public static function cancellableStatuses(): iterable
    {
        yield 'pending' => [OrderStatus::Pending];
        yield 'key_reserved' => [OrderStatus::KeyReserved];
        yield 'paid' => [OrderStatus::Paid];
    }

    #[DataProvider('cancellableStatuses')]
    public function testRequestSetsFlagKeepsStatusAndRaisesEvent(OrderStatus $status): void
    {
        $order = OrderFactory::variants($status)[0];
        $requested = $order->requestCancellation(OrderFactory::later(500));

        self::assertSame($status, $requested->status);
        self::assertTrue($requested->cancellationRequested);
        self::assertSame($order->version + 1, $requested->version);
        self::assertInstanceOf(OrderCancellationRequested::class, OrderFactory::lastEvent($requested));
    }

    /**
     * @return iterable<string, array{OrderStatus}>
     */
    public static function silentlyIdempotentStatuses(): iterable
    {
        yield 'compensating' => [OrderStatus::Compensating];
        yield 'cancelled' => [OrderStatus::Cancelled];
    }

    #[DataProvider('silentlyIdempotentStatuses')]
    public function testCompensatingAndCancelledIgnoreRequest(OrderStatus $status): void
    {
        foreach (OrderFactory::variants($status) as $order) {
            self::assertSame($order, $order->requestCancellation(OrderFactory::later(500)));
        }
    }

    /**
     * @return iterable<string, array{OrderStatus}>
     */
    public static function notCancellableStatuses(): iterable
    {
        yield 'completed' => [OrderStatus::Completed];
        yield 'refunding' => [OrderStatus::Refunding];
        yield 'refunded' => [OrderStatus::Refunded];
        yield 'failed' => [OrderStatus::Failed];
        yield 'manual_review' => [OrderStatus::ManualReview];
    }

    #[DataProvider('notCancellableStatuses')]
    public function testNotCancellableStatusesRejected(OrderStatus $status): void
    {
        foreach (OrderFactory::variants($status) as $order) {
            try {
                $order->requestCancellation(OrderFactory::later(500));
                self::fail('Expected OrderNotCancellable for ' . $status->value);
            } catch (OrderNotCancellable $e) {
                self::assertSame($status, $e->status);
            }
        }
    }

    public function testCancelBeforeStartCancelsPendingOrder(): void
    {
        $cancelled = OrderFactory::pending()->cancelBeforeStart(OrderFactory::later(5));

        self::assertSame(OrderStatus::Cancelled, $cancelled->status);
        self::assertTrue($cancelled->cancellationRequested);
    }
}
