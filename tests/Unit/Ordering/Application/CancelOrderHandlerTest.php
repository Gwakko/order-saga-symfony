<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\Command\CancelOrder\CancelOrderCommand;
use App\Ordering\Application\Command\CancelOrder\CancelOrderHandler;
use App\Ordering\Domain\Event\OrderCancellationRequested;
use App\Ordering\Domain\Exception\OrderNotCancellable;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Application\Exception\ConcurrencyConflict;
use App\Shared\Application\Exception\OrderNotFound;
use App\Tests\Support\Fake\FakeOrderSagaSignals;
use App\Tests\Support\Fake\InMemoryOrderRepository;
use App\Tests\Support\Fake\NoopTransactionManager;
use App\Tests\Support\Fake\RecordingLogger;
use App\Tests\Unit\Ordering\Domain\OrderFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * T-U-45: CancelOrder (EC-70 foreign order, EC-79 concurrent change).
 */
#[Group('T-U-45')]
final class CancelOrderHandlerTest extends TestCase
{
    private const OTHER_CUSTOMER_ID = '0190a5c4-0000-7000-8000-0000000000c2';

    private InMemoryOrderRepository $orders;
    private FakeOrderSagaSignals $signals;
    private RecordingLogger $logger;
    private CancelOrderHandler $handler;

    protected function setUp(): void
    {
        $this->orders = new InMemoryOrderRepository();
        $this->signals = new FakeOrderSagaSignals();
        $this->logger = new RecordingLogger();
        $this->handler = new CancelOrderHandler(
            $this->orders,
            $this->signals,
            new NoopTransactionManager(),
            new MockClock('2026-05-01T10:05:00Z'),
            $this->logger,
        );
    }

    private function cancel(string $customerId = OrderFactory::CUSTOMER_ID): \App\Ordering\Application\Command\CancelOrder\CancelOrderOutput
    {
        return ($this->handler)(new CancelOrderCommand(OrderFactory::ORDER_ID, $customerId));
    }

    private function seed(Order $order): void
    {
        $this->orders->seed($order);
    }

    public function testSetsFlagStoresEventAndSignalsTheWorkflow(): void
    {
        $this->seed(OrderFactory::keyReserved());

        $output = $this->cancel();

        self::assertTrue($output->cancellationRequested);
        self::assertSame(OrderStatus::KeyReserved, $output->status);
        self::assertTrue($this->orders->get(OrderFactory::keyReserved()->id)->cancellationRequested);
        self::assertInstanceOf(OrderCancellationRequested::class, $this->orders->events[0]);
        self::assertSame([OrderFactory::ORDER_ID], $this->signals->cancellations);
    }

    public function testPendingOrderIsSignalledToo(): void
    {
        $this->seed(OrderFactory::pending());

        $this->cancel();

        self::assertSame([OrderFactory::ORDER_ID], $this->signals->cancellations);
    }

    public function testRepeatedRequestWritesNothingNew(): void
    {
        $this->seed(OrderFactory::keyReserved()->requestCancellation(OrderFactory::later(12)));

        $output = $this->cancel();

        self::assertTrue($output->cancellationRequested);
        self::assertSame(0, $this->orders->saves);
        self::assertSame([], $this->orders->events);
    }

    /**
     * EC-70: a foreign order is indistinguishable from a missing one, and nothing is touched or signalled.
     */
    public function testForeignOrderIsNotFound(): void
    {
        $this->seed(OrderFactory::keyReserved());

        try {
            $this->cancel(self::OTHER_CUSTOMER_ID);
            self::fail('Expected OrderNotFound');
        } catch (OrderNotFound) {
        }

        self::assertFalse($this->orders->get(OrderFactory::keyReserved()->id)->cancellationRequested);
        self::assertSame([], $this->signals->cancellations);
    }

    public function testMissingOrderIsNotFound(): void
    {
        $this->expectException(OrderNotFound::class);

        $this->cancel();
    }

    public function testCompletedOrderIsNotCancellable(): void
    {
        $this->seed(OrderFactory::completed());

        $this->expectException(OrderNotCancellable::class);

        $this->cancel();
    }

    /**
     * EC-79: two conflicts are retried, the third try succeeds.
     */
    public function testConflictIsRetriedAndSucceedsOnLastAttempt(): void
    {
        $this->seed(OrderFactory::paid());
        $this->orders->failNextSaves(2);

        $output = $this->cancel();

        self::assertTrue($output->cancellationRequested);
        self::assertSame(OrderStatus::Paid, $output->status);
        self::assertSame(1, $this->orders->saves);
    }

    /**
     * EC-79: three conflicts in a row surface as ConcurrencyConflict; the workflow is not signalled.
     */
    public function testConflictAfterThreeAttemptsIsReported(): void
    {
        $this->seed(OrderFactory::paid());
        $this->orders->failNextSaves(3);

        try {
            $this->cancel();
            self::fail('Expected ConcurrencyConflict');
        } catch (ConcurrencyConflict) {
        }

        self::assertSame(0, $this->orders->saves);
        self::assertSame([], $this->signals->cancellations);
    }

    public function testSignalFailureKeepsTheFlagAndIsLogged(): void
    {
        $this->seed(OrderFactory::keyReserved());
        $this->signals->failure = new \RuntimeException('temporal is down');

        $output = $this->cancel();

        self::assertTrue($output->cancellationRequested);
        self::assertTrue($this->orders->get(OrderFactory::keyReserved()->id)->cancellationRequested);
        self::assertTrue($this->logger->hasRecord('warning'));
    }
}
