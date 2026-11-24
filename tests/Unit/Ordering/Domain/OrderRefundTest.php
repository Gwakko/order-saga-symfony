<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Domain;

use App\Ordering\Domain\Exception\OrderNotRefundable;
use App\Ordering\Domain\Exception\RefundWindowClosed;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Domain\DomainErrorCode;
use App\Shared\Domain\RefundWindow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class OrderRefundTest extends TestCase
{
    private const FOURTEEN_DAYS = 14 * 86400;

    /**
     * EC-59: Completed, less than 14 days.
     */
    #[Group('T-U-08')]
    public function testStartRefundInsideWindow(): void
    {
        $completed = OrderFactory::completed();
        $refunding = $completed->startRefund(OrderFactory::later(30 + 86400), new RefundWindow());

        self::assertSame(OrderStatus::Refunding, $refunding->status);
        self::assertSame($completed->version + 1, $refunding->version);
        self::assertSame($completed->deliveredKeyId, $refunding->deliveredKeyId);
    }

    #[Group('T-U-08')]
    public function testRefundWindowBoundary(): void
    {
        $completed = OrderFactory::completed(); // completedAt = t0 + 30 s
        $atBoundary = OrderFactory::later(30 + self::FOURTEEN_DAYS);

        self::assertSame(OrderStatus::Refunding, $completed->startRefund($atBoundary, new RefundWindow())->status);

        $this->expectException(RefundWindowClosed::class);
        $completed->startRefund($atBoundary->modify('+1 second'), new RefundWindow());
    }

    #[Group('T-U-08')]
    public function testClosedWindowRaisesRefundWindowClosed(): void
    {
        try {
            OrderFactory::completed()->startRefund(OrderFactory::later(30 + self::FOURTEEN_DAYS + 1), new RefundWindow());
            self::fail('Expected RefundWindowClosed');
        } catch (RefundWindowClosed $e) {
            self::assertSame(DomainErrorCode::RefundWindowClosed, $e->code());
        }
    }

    /**
     * @return iterable<string, array{OrderStatus}>
     */
    public static function notRefundableStatuses(): iterable
    {
        foreach (OrderStatus::cases() as $status) {
            if ($status !== OrderStatus::Completed) {
                yield $status->value => [$status];
            }
        }
    }

    /**
     * EC-62: Paid / Failed (and every other status except Completed) cannot be refunded.
     */
    #[Group('T-U-10')]
    #[DataProvider('notRefundableStatuses')]
    public function testNotRefundableStatuses(OrderStatus $status): void
    {
        foreach (OrderFactory::variants($status) as $order) {
            try {
                $order->startRefund(OrderFactory::later(200), new RefundWindow());
                self::fail('Expected OrderNotRefundable for ' . $status->value);
            } catch (OrderNotRefundable $e) {
                self::assertSame(DomainErrorCode::OrderNotRefundable, $e->code());
                self::assertSame($status, $e->status);
            }
        }
    }

    public function testFinishRefundMovesRefundingToRefunded(): void
    {
        $refunded = OrderFactory::refunding()->finishRefund(OrderFactory::later(200));

        self::assertSame(OrderStatus::Refunded, $refunded->status);
        self::assertTrue($refunded->status->isTerminal());
    }
}
