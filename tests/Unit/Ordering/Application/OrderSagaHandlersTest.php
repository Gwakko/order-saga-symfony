<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Inventory\Domain\ProductKey;
use App\Ordering\Application\Command\CancelOrderBeforeStart\CancelOrderBeforeStartCommand;
use App\Ordering\Application\Command\CancelOrderBeforeStart\CancelOrderBeforeStartHandler;
use App\Ordering\Application\Command\CompleteOrder\CompleteOrderCommand;
use App\Ordering\Application\Command\CompleteOrder\CompleteOrderHandler;
use App\Ordering\Application\Command\FailOrder\FailOrderCommand;
use App\Ordering\Application\Command\FailOrder\FailOrderHandler;
use App\Ordering\Application\Command\FinishOrderCompensation\FinishOrderCompensationCommand;
use App\Ordering\Application\Command\FinishOrderCompensation\FinishOrderCompensationHandler;
use App\Ordering\Application\Command\FinishOrderRefund\FinishOrderRefundCommand;
use App\Ordering\Application\Command\FinishOrderRefund\FinishOrderRefundHandler;
use App\Ordering\Application\Command\MarkOrderKeyReserved\MarkOrderKeyReservedCommand;
use App\Ordering\Application\Command\MarkOrderKeyReserved\MarkOrderKeyReservedHandler;
use App\Ordering\Application\Command\MarkOrderManualReview\MarkOrderManualReviewCommand;
use App\Ordering\Application\Command\MarkOrderManualReview\MarkOrderManualReviewHandler;
use App\Ordering\Application\Command\MarkOrderPaid\MarkOrderPaidCommand;
use App\Ordering\Application\Command\MarkOrderPaid\MarkOrderPaidHandler;
use App\Ordering\Application\Command\StartOrderCompensation\StartOrderCompensationCommand;
use App\Ordering\Application\Command\StartOrderCompensation\StartOrderCompensationHandler;
use App\Ordering\Application\Exception\DeliveredKeyNotFound;
use App\Ordering\Application\OrderMutator;
use App\Ordering\Application\Query\IsCancellationRequested\IsCancellationRequestedHandler;
use App\Ordering\Application\Query\IsCancellationRequested\IsCancellationRequestedQuery;
use App\Ordering\Domain\Event\OrderCompleted;
use App\Ordering\Domain\Exception\IllegalOrderTransition;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Application\Exception\OrderNotFound;
use App\Shared\Domain\FailureReason;
use App\Shared\Domain\Identifier\ProductKeyId;
use App\Shared\Domain\KeyCode;
use App\Tests\Support\Fake\FakeDeliveredKeyReader;
use App\Tests\Support\Fake\InMemoryKeyInventory;
use App\Tests\Support\Fake\InMemoryOrderRepository;
use App\Tests\Support\Fake\NoopTransactionManager;
use App\Tests\Support\Fake\RecordingLogger;
use App\Tests\Unit\Ordering\Domain\OrderFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The Ordering commands the saga activities call (02_use_cases.md section 2): one transition each, idempotent repeats.
 */
#[Group('M3.3')]
final class OrderSagaHandlersTest extends TestCase
{
    private InMemoryOrderRepository $orders;
    private OrderMutator $mutator;

    protected function setUp(): void
    {
        $this->orders = new InMemoryOrderRepository();
        $this->mutator = new OrderMutator($this->orders, new NoopTransactionManager(), new MockClock('2026-05-01T10:05:00Z'));
    }

    private function seed(Order $order): void
    {
        $this->orders->seed($order);
    }

    private function stored(): Order
    {
        return $this->orders->get(OrderFactory::pending()->id);
    }

    public function testMarkKeyReservedMovesOrderAndRepeatWritesNothing(): void
    {
        $this->seed(OrderFactory::pending());
        $handler = new MarkOrderKeyReservedHandler($this->mutator);
        $command = new MarkOrderKeyReservedCommand(OrderFactory::ORDER_ID, OrderFactory::RESERVATION_ID);

        $first = $handler($command);
        $second = $handler($command);

        self::assertSame(OrderStatus::KeyReserved, $this->stored()->status);
        self::assertSame(2, $first->version);
        self::assertSame(2, $second->version);
        self::assertSame(1, $this->orders->saves);
    }

    public function testMarkKeyReservedWithAnotherReservationIsIllegal(): void
    {
        $this->seed(OrderFactory::keyReserved());

        $this->expectException(IllegalOrderTransition::class);

        (new MarkOrderKeyReservedHandler($this->mutator))(new MarkOrderKeyReservedCommand(OrderFactory::ORDER_ID, OrderFactory::OTHER_RESERVATION_ID));
    }

    public function testMarkPaidMovesOrderAndIsIdempotent(): void
    {
        $this->seed(OrderFactory::keyReserved());
        $handler = new MarkOrderPaidHandler($this->mutator);
        $command = new MarkOrderPaidCommand(OrderFactory::ORDER_ID, OrderFactory::PAYMENT_ID);

        $handler($command);
        $output = $handler($command);

        self::assertSame(OrderStatus::Paid, $this->stored()->status);
        self::assertSame(3, $output->version);
        self::assertSame(1, $this->orders->saves);
    }

    public function testMarkPaidWithAnotherPaymentIsIllegal(): void
    {
        $this->seed(OrderFactory::paid());

        $this->expectException(IllegalOrderTransition::class);

        (new MarkOrderPaidHandler($this->mutator))(new MarkOrderPaidCommand(OrderFactory::ORDER_ID, OrderFactory::OTHER_PAYMENT_ID));
    }

    public function testMarkPaidOnPendingOrderIsIllegal(): void
    {
        $this->seed(OrderFactory::pending());

        $this->expectException(IllegalOrderTransition::class);

        (new MarkOrderPaidHandler($this->mutator))(new MarkOrderPaidCommand(OrderFactory::ORDER_ID, OrderFactory::PAYMENT_ID));
    }

    public function testMissingOrderIsNotFound(): void
    {
        $this->expectException(OrderNotFound::class);

        (new MarkOrderPaidHandler($this->mutator))(new MarkOrderPaidCommand(OrderFactory::ORDER_ID, OrderFactory::PAYMENT_ID));
    }

    private function keyReader(): FakeDeliveredKeyReader
    {
        $keys = new InMemoryKeyInventory();
        $keys->seed(ProductKey::add(ProductKeyId::fromString(OrderFactory::KEY_ID), OrderFactory::pending()->productId, new KeyCode('ABCDE-FGHIJ-AB12')));

        return new FakeDeliveredKeyReader($keys);
    }

    /**
     * INV-17: the event carries the masked code only.
     */
    public function testCompleteOrderDeliversTheKeyWithMaskedCodeInTheEvent(): void
    {
        $this->seed(OrderFactory::paid());
        $handler = new CompleteOrderHandler($this->mutator, $this->keyReader());
        $command = new CompleteOrderCommand(OrderFactory::ORDER_ID, OrderFactory::KEY_ID);

        $handler($command);
        $handler($command);

        $order = $this->stored();
        self::assertSame(OrderStatus::Completed, $order->status);
        self::assertSame(OrderFactory::KEY_ID, $order->deliveredKeyId?->value);
        self::assertEquals(OrderFactory::t0()->modify('+5 minutes'), $order->completedAt);
        self::assertSame(1, $this->orders->saves);

        $event = $this->orders->events[0];
        self::assertInstanceOf(OrderCompleted::class, $event);
        self::assertStringNotContainsString('ABCDE-FGHIJ', $event->keyMasked);
        self::assertStringEndsWith('AB12', $event->keyMasked);
    }

    public function testCompleteOrderWithUnknownKeyFailsBeforeAnyWrite(): void
    {
        $this->seed(OrderFactory::paid());
        $handler = new CompleteOrderHandler($this->mutator, new FakeDeliveredKeyReader(new InMemoryKeyInventory()));

        try {
            $handler(new CompleteOrderCommand(OrderFactory::ORDER_ID, OrderFactory::KEY_ID));
            self::fail('Expected DeliveredKeyNotFound');
        } catch (DeliveredKeyNotFound) {
        }

        self::assertSame(OrderStatus::Paid, $this->stored()->status);
    }

    public function testCompleteOrderBeforePaymentIsIllegal(): void
    {
        $this->seed(OrderFactory::keyReserved());

        $this->expectException(IllegalOrderTransition::class);

        (new CompleteOrderHandler($this->mutator, $this->keyReader()))(new CompleteOrderCommand(OrderFactory::ORDER_ID, OrderFactory::KEY_ID));
    }

    public function testFailOrderFromPendingAndRepeat(): void
    {
        $this->seed(OrderFactory::pending());
        $handler = new FailOrderHandler($this->mutator);
        $command = new FailOrderCommand(OrderFactory::ORDER_ID, FailureReason::OutOfStock);

        $handler($command);
        $handler($command);

        $order = $this->stored();
        self::assertSame(OrderStatus::Failed, $order->status);
        self::assertSame(FailureReason::OutOfStock, $order->failureReason);
        self::assertSame(1, $this->orders->saves);
    }

    public function testFailOrderOutsidePendingIsIllegal(): void
    {
        $this->seed(OrderFactory::keyReserved());

        $this->expectException(IllegalOrderTransition::class);

        (new FailOrderHandler($this->mutator))(new FailOrderCommand(OrderFactory::ORDER_ID, FailureReason::OutOfStock));
    }

    public function testCancelBeforeStartAndRepeat(): void
    {
        $this->seed(OrderFactory::pending()->requestCancellation(OrderFactory::later(1)));
        $handler = new CancelOrderBeforeStartHandler($this->mutator);

        $handler(new CancelOrderBeforeStartCommand(OrderFactory::ORDER_ID));
        $handler(new CancelOrderBeforeStartCommand(OrderFactory::ORDER_ID));

        $order = $this->stored();
        self::assertSame(OrderStatus::Cancelled, $order->status);
        self::assertSame(FailureReason::CancelledByCustomer, $order->failureReason);
        self::assertSame(1, $this->orders->saves);
    }

    public function testStartCompensationRecordsReasonAndIsIdempotent(): void
    {
        $this->seed(OrderFactory::keyReserved());
        $handler = new StartOrderCompensationHandler($this->mutator);
        $command = new StartOrderCompensationCommand(OrderFactory::ORDER_ID, FailureReason::PaymentDeclined);

        $handler($command);
        $handler($command);

        self::assertSame(OrderStatus::Compensating, $this->stored()->status);
        self::assertSame(FailureReason::PaymentDeclined, $this->stored()->compensationReason);
        self::assertSame(1, $this->orders->saves);
    }

    public function testFinishCompensationEndsFailedWithTheStoredReason(): void
    {
        $this->seed(OrderFactory::compensating(false));
        $handler = new FinishOrderCompensationHandler($this->mutator);

        $first = $handler(new FinishOrderCompensationCommand(OrderFactory::ORDER_ID));
        $second = $handler(new FinishOrderCompensationCommand(OrderFactory::ORDER_ID));

        self::assertSame(OrderStatus::Failed, $first->status);
        self::assertSame(OrderStatus::Failed, $second->status);
        self::assertSame(FailureReason::PaymentDeclined, $this->stored()->failureReason);
        self::assertSame(1, $this->orders->saves);
    }

    public function testFinishCompensationEndsCancelledWhenTheCustomerAsked(): void
    {
        $this->seed(OrderFactory::compensating(true));

        $output = (new FinishOrderCompensationHandler($this->mutator))(new FinishOrderCompensationCommand(OrderFactory::ORDER_ID));

        self::assertSame(OrderStatus::Cancelled, $output->status);
        self::assertSame(FailureReason::CancelledByCustomer, $this->stored()->failureReason);
    }

    public function testFinishCompensationOutsideCompensationIsIllegal(): void
    {
        $this->seed(OrderFactory::paid());

        $this->expectException(IllegalOrderTransition::class);

        (new FinishOrderCompensationHandler($this->mutator))(new FinishOrderCompensationCommand(OrderFactory::ORDER_ID));
    }

    public function testManualReviewIsRecordedLoggedAsCriticalAndRepeatSafe(): void
    {
        $this->seed(OrderFactory::compensating());
        $logger = new RecordingLogger();
        $handler = new MarkOrderManualReviewHandler($this->mutator, $logger);
        $command = new MarkOrderManualReviewCommand(OrderFactory::ORDER_ID, FailureReason::CompensationFailed, 'RefundPayment');

        $handler($command);
        $handler($command);

        self::assertSame(OrderStatus::ManualReview, $this->stored()->status);
        self::assertSame(FailureReason::CompensationFailed, $this->stored()->failureReason);
        self::assertTrue($logger->hasRecord('critical'));
        self::assertSame(1, $this->orders->saves);
    }

    public function testManualReviewFromPendingIsIllegal(): void
    {
        $this->seed(OrderFactory::pending());

        $this->expectException(IllegalOrderTransition::class);

        (new MarkOrderManualReviewHandler($this->mutator, new RecordingLogger()))(
            new MarkOrderManualReviewCommand(OrderFactory::ORDER_ID, FailureReason::CompensationFailed, 'RefundPayment'),
        );
    }

    public function testFinishRefundAndRepeat(): void
    {
        $this->seed(OrderFactory::refunding());
        $handler = new FinishOrderRefundHandler($this->mutator);

        $handler(new FinishOrderRefundCommand(OrderFactory::ORDER_ID));
        $handler(new FinishOrderRefundCommand(OrderFactory::ORDER_ID));

        self::assertSame(OrderStatus::Refunded, $this->stored()->status);
        self::assertSame(1, $this->orders->saves);
    }

    public function testFinishRefundOnACompletedOrderIsIllegal(): void
    {
        $this->seed(OrderFactory::completed());

        $this->expectException(IllegalOrderTransition::class);

        (new FinishOrderRefundHandler($this->mutator))(new FinishOrderRefundCommand(OrderFactory::ORDER_ID));
    }

    public function testIsCancellationRequestedReadsTheFlag(): void
    {
        $handler = new IsCancellationRequestedHandler($this->orders);
        $query = new IsCancellationRequestedQuery(OrderFactory::ORDER_ID);

        $this->seed(OrderFactory::keyReserved());
        self::assertFalse($handler($query)->requested);

        $this->seed(OrderFactory::keyReserved()->requestCancellation(OrderFactory::later(12)));
        self::assertTrue($handler($query)->requested);
    }
}
