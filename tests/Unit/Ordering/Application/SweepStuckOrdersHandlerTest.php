<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\Command\MarkOrderManualReview\MarkOrderManualReviewHandler;
use App\Ordering\Application\Command\SweepStuckOrders\SweepStuckOrdersCommand;
use App\Ordering\Application\Command\SweepStuckOrders\SweepStuckOrdersHandler;
use App\Ordering\Application\OrderMutator;
use App\Ordering\Application\Port\SagaWorkflowKind;
use App\Ordering\Application\Port\StuckOrder;
use App\Ordering\Application\Port\StuckOrderFinder;
use App\Ordering\Domain\Event\OrderNeedsManualReview;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Application\Exception\InvalidInput;
use App\Shared\Domain\Currency;
use App\Shared\Domain\FailureReason;
use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\PaymentId;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Identifier\ReservationId;
use App\Shared\Domain\Money;
use App\Shared\Domain\RefundWindow;
use App\Tests\Support\Fake\FakeWorkflowStatusChecker;
use App\Tests\Support\Fake\InMemoryOrderRepository;
use App\Tests\Support\Fake\InMemoryStuckOrderFinder;
use App\Tests\Support\Fake\NoopTransactionManager;
use App\Tests\Support\Fake\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * M8a sweeper: an order in an intermediate status, old enough and without a running workflow, is parked in
 * `ManualReview(SagaInterrupted)`; everything else is left alone.
 */
#[Group('M8a')]
final class SweepStuckOrdersHandlerTest extends TestCase
{
    private const string NOW = '2026-05-01T12:00:00Z';
    private const int OLDER_THAN = 1800;

    private InMemoryOrderRepository $orders;
    private FakeWorkflowStatusChecker $workflows;
    private SweepStuckOrdersHandler $handler;

    protected function setUp(): void
    {
        $this->orders = new InMemoryOrderRepository();
        $this->workflows = new FakeWorkflowStatusChecker();
        $this->handler = $this->handlerWith(new InMemoryStuckOrderFinder($this->orders));
    }

    private function handlerWith(StuckOrderFinder $finder): SweepStuckOrdersHandler
    {
        $clock = new MockClock(self::NOW);

        return new SweepStuckOrdersHandler(
            $finder,
            $this->workflows,
            new MarkOrderManualReviewHandler(new OrderMutator($this->orders, new NoopTransactionManager(), $clock), new RecordingLogger()),
            $clock,
            new RecordingLogger(),
        );
    }

    private function sweep(): \App\Ordering\Application\Command\SweepStuckOrders\SweepStuckOrdersOutput
    {
        return ($this->handler)(new SweepStuckOrdersCommand(self::OLDER_THAN));
    }

    /** An order of the given status whose last change was `$ageSeconds` ago. */
    private function seed(OrderStatus $status, int $ageSeconds = 3600, ?string $id = null): Order
    {
        $at = (new \DateTimeImmutable(self::NOW))->modify(sprintf('-%d seconds', $ageSeconds));
        $orderId = OrderId::fromString($id ?? '0190a5c4-0000-7000-8000-' . str_pad((string) random_int(1, 999_999_999_999), 12, '0', STR_PAD_LEFT));
        $price = Money::positive(1999, Currency::EUR);
        $order = Order::place($orderId, CustomerId::fromString('0190a5c4-0000-7000-8000-000000000002'), ProductId::fromString('0190a5c4-0000-7000-8000-000000000003'), $price, $price, $at->modify('-1 second'));
        $keyReserved = static fn(Order $o): Order => $o->markKeyReserved(ReservationId::fromString('0190a5c4-0000-7000-8000-000000000004'), $at);
        $paid = static fn(Order $o): Order => $keyReserved($o)->markPaid(PaymentId::fromString('0190a5c4-0000-7000-8000-000000000005'), $at);

        $order = match ($status) {
            OrderStatus::Pending => Order::place($orderId, CustomerId::fromString('0190a5c4-0000-7000-8000-000000000002'), ProductId::fromString('0190a5c4-0000-7000-8000-000000000003'), $price, $price, $at),
            OrderStatus::KeyReserved => $keyReserved($order),
            OrderStatus::Paid => $paid($order),
            OrderStatus::Compensating => $keyReserved($order)->startCompensation(FailureReason::PaymentDeclined, $at),
            OrderStatus::Completed => $paid($order)->complete(\App\Shared\Domain\Identifier\ProductKeyId::fromString('0190a5c4-0000-7000-8000-000000000006'), new \App\Shared\Domain\KeyCode('ABCDE-FGHIJ-AB12'), $at),
            OrderStatus::Refunding => $paid($order)->complete(\App\Shared\Domain\Identifier\ProductKeyId::fromString('0190a5c4-0000-7000-8000-000000000006'), new \App\Shared\Domain\KeyCode('ABCDE-FGHIJ-AB12'), $at)->startRefund($at, new RefundWindow()),
            OrderStatus::Cancelled => $order->cancelBeforeStart($at),
            OrderStatus::Failed => $order->fail(FailureReason::OutOfStock, $at),
            OrderStatus::ManualReview => $order->requireManualReview(FailureReason::SagaInterrupted, 'Pending', $at),
            OrderStatus::Refunded => $paid($order)->complete(\App\Shared\Domain\Identifier\ProductKeyId::fromString('0190a5c4-0000-7000-8000-000000000006'), new \App\Shared\Domain\KeyCode('ABCDE-FGHIJ-AB12'), $at)->startRefund($at, new RefundWindow())->finishRefund($at),
        };
        $this->orders->seed($order);

        return $order;
    }

    /**
     * @return iterable<string, array{OrderStatus}>
     */
    public static function intermediateStatuses(): iterable
    {
        yield 'pending' => [OrderStatus::Pending];
        yield 'key reserved' => [OrderStatus::KeyReserved];
        yield 'paid' => [OrderStatus::Paid];
        yield 'compensating' => [OrderStatus::Compensating];
        yield 'refunding' => [OrderStatus::Refunding];
    }

    #[Group('T-M8A-14')]
    #[DataProvider('intermediateStatuses')]
    public function testOldOrderWithoutAWorkflowGoesToManualReview(OrderStatus $status): void
    {
        $order = $this->seed($status);

        $result = $this->sweep();

        $stored = $this->orders->get($order->id);
        self::assertSame(OrderStatus::ManualReview, $stored->status);
        self::assertSame(FailureReason::SagaInterrupted, $stored->failureReason);
        self::assertSame(1, $result->checked);
        self::assertSame(1, $result->interrupted);
        $event = end($this->orders->events);
        self::assertInstanceOf(OrderNeedsManualReview::class, $event);
        self::assertSame(FailureReason::SagaInterrupted, $event->reason);
        self::assertSame($status->value, $event->step, 'the step records the status the order was stuck in');
    }

    #[Group('T-M8A-15')]
    #[DataProvider('intermediateStatuses')]
    public function testOrderWithARunningWorkflowIsNotTouched(OrderStatus $status): void
    {
        $order = $this->seed($status);
        $this->workflows->running($order->id, $status === OrderStatus::Refunding ? SagaWorkflowKind::Refund : SagaWorkflowKind::Fulfillment);

        $result = $this->sweep();

        self::assertSame($status, $this->orders->get($order->id)->status);
        self::assertSame(1, $result->stillRunning);
        self::assertSame(0, $result->interrupted);
        self::assertSame(0, $this->orders->saves);
    }

    #[Group('T-M8A-16')]
    public function testARefundingOrderIsJudgedByTheRefundWorkflowNotTheSaga(): void
    {
        $order = $this->seed(OrderStatus::Refunding);
        $this->workflows->running($order->id, SagaWorkflowKind::Fulfillment);

        $this->sweep();

        self::assertSame(OrderStatus::ManualReview, $this->orders->get($order->id)->status);
        self::assertSame(['Refund:' . $order->id->value], $this->workflows->asked);
    }

    #[Group('T-M8A-17')]
    public function testFreshOrderIsNotTouched(): void
    {
        $fresh = $this->seed(OrderStatus::Paid, ageSeconds: self::OLDER_THAN - 60);
        $edge = $this->seed(OrderStatus::Pending, ageSeconds: self::OLDER_THAN);

        $result = $this->sweep();

        self::assertSame(OrderStatus::Paid, $this->orders->get($fresh->id)->status);
        self::assertSame(OrderStatus::Pending, $this->orders->get($edge->id)->status, 'exactly at the threshold is still fresh');
        self::assertSame(0, $result->checked);
        self::assertSame([], $this->workflows->asked, 'the workflow engine is not even asked');
    }

    #[Group('T-M8A-18')]
    public function testSecondRunChangesNothing(): void
    {
        $this->seed(OrderStatus::KeyReserved);
        $this->seed(OrderStatus::Paid);

        $first = $this->sweep();
        $savesAfterFirst = $this->orders->saves;
        $eventsAfterFirst = count($this->orders->events);
        $second = $this->sweep();

        self::assertSame(2, $first->interrupted);
        self::assertSame(0, $second->checked);
        self::assertSame(0, $second->interrupted);
        self::assertSame($savesAfterFirst, $this->orders->saves);
        self::assertCount($eventsAfterFirst, $this->orders->events);
    }

    #[Group('T-M8A-19')]
    public function testOrdersInOtherStatusesAreNeverCandidates(): void
    {
        foreach ([OrderStatus::Completed, OrderStatus::Cancelled, OrderStatus::Failed, OrderStatus::Refunded, OrderStatus::ManualReview] as $status) {
            $order = $this->seed($status);
            $this->sweep();

            self::assertSame($status, $this->orders->get($order->id)->status);
        }

        self::assertSame([], $this->workflows->asked);
    }

    #[Group('T-M8A-20')]
    public function testUnreachableWorkflowEngineLeavesTheOrderForTheNextRun(): void
    {
        $unknown = $this->seed(OrderStatus::Paid, 7200);
        $dead = $this->seed(OrderStatus::Paid, 3600);
        $this->workflows->unavailable($unknown->id);

        $result = $this->sweep();

        self::assertSame(OrderStatus::Paid, $this->orders->get($unknown->id)->status, 'not "dead" just because we could not ask');
        self::assertSame(OrderStatus::ManualReview, $this->orders->get($dead->id)->status, 'one failure does not stop the sweep');
        self::assertSame(1, $result->skipped);
        self::assertSame(1, $result->interrupted);
    }

    #[Group('T-M8A-21')]
    public function testOrderThatMovedOnAfterTheQueryIsSkipped(): void
    {
        // The finder saw `Paid`; by the time of the transition the workflow has completed the order.
        $order = $this->seed(OrderStatus::Completed);
        $stale = new class ($order->id) implements StuckOrderFinder {
            public function __construct(private readonly OrderId $id) {}

            public function findUpdatedBefore(\DateTimeImmutable $updatedBefore, int $limit): array
            {
                return [new StuckOrder($this->id, OrderStatus::Paid)];
            }
        };

        $result = $this->handlerWith($stale)(new SweepStuckOrdersCommand(self::OLDER_THAN));

        self::assertSame(OrderStatus::Completed, $this->orders->get($order->id)->status);
        self::assertSame(1, $result->skipped);
        self::assertSame(0, $result->interrupted);
    }

    #[Group('T-M8A-22')]
    public function testLimitCapsTheRunAndOldestGoFirst(): void
    {
        $oldest = $this->seed(OrderStatus::Paid, 9000);
        $newer = $this->seed(OrderStatus::Paid, 5000);

        $result = ($this->handler)(new SweepStuckOrdersCommand(self::OLDER_THAN, 1));

        self::assertSame(1, $result->checked);
        self::assertSame(OrderStatus::ManualReview, $this->orders->get($oldest->id)->status);
        self::assertSame(OrderStatus::Paid, $this->orders->get($newer->id)->status);
    }

    #[Group('T-M8A-23')]
    public function testCommandRejectsBadInput(): void
    {
        $this->expectException(InvalidInput::class);

        new SweepStuckOrdersCommand(10);
    }

    #[Group('T-M8A-23')]
    public function testCommandRejectsBadLimit(): void
    {
        $this->expectException(InvalidInput::class);

        new SweepStuckOrdersCommand(self::OLDER_THAN, 0);
    }
}
