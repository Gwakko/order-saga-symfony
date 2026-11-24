<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\Command\RequestRefund\RequestRefundCommand;
use App\Ordering\Application\Command\RequestRefund\RequestRefundHandler;
use App\Ordering\Domain\Event\OrderRefundStarted;
use App\Ordering\Domain\Exception\OrderNotRefundable;
use App\Ordering\Domain\Exception\RefundWindowClosed;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Application\Exception\OrderNotFound;
use App\Tests\Support\Fake\InMemoryOrderRepository;
use App\Tests\Support\Fake\NoopTransactionManager;
use App\Tests\Unit\Ordering\Domain\OrderFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * RequestRefund (02_use_cases.md section 1.3, EC-62, EC-70).
 */
#[Group('M3.2')]
final class RequestRefundHandlerTest extends TestCase
{
    private InMemoryOrderRepository $orders;

    protected function setUp(): void
    {
        $this->orders = new InMemoryOrderRepository();
    }

    private function handle(string $now = '2026-05-02T10:00:00Z', string $customerId = OrderFactory::CUSTOMER_ID): \App\Ordering\Application\Command\RequestRefund\RequestRefundOutput
    {
        $handler = new RequestRefundHandler($this->orders, new NoopTransactionManager(), new MockClock($now));

        return $handler(new RequestRefundCommand(OrderFactory::ORDER_ID, $customerId));
    }

    private function seed(Order $order): void
    {
        $this->orders->seed($order);
    }

    public function testStartsRefundAndRecordsEvent(): void
    {
        $this->seed(OrderFactory::completed());

        $output = $this->handle();

        self::assertSame(OrderStatus::Refunding, $output->status);
        self::assertInstanceOf(OrderRefundStarted::class, $this->orders->events[0]);
    }

    public function testRepeatOnRefundingOrRefundedAnswersWithCurrentStatus(): void
    {
        $this->seed(OrderFactory::refunding());
        self::assertSame(OrderStatus::Refunding, $this->handle()->status);

        $this->seed(OrderFactory::refunded());
        self::assertSame(OrderStatus::Refunded, $this->handle()->status);

        self::assertSame(0, $this->orders->saves);
    }

    public function testNotCompletedIsNotRefundable(): void
    {
        $this->seed(OrderFactory::paid());

        $this->expectException(OrderNotRefundable::class);

        $this->handle();
    }

    public function testClosedWindowIsRejected(): void
    {
        $this->seed(OrderFactory::completed());

        $this->expectException(RefundWindowClosed::class);

        $this->handle('2026-06-01T10:00:00Z');
    }

    public function testForeignOrderIsNotFound(): void
    {
        $this->seed(OrderFactory::completed());

        $this->expectException(OrderNotFound::class);

        $this->handle(customerId: '0190a5c4-0000-7000-8000-0000000000c2');
    }
}
