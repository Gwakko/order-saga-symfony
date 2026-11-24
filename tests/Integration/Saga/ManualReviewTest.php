<?php

declare(strict_types=1);

namespace App\Tests\Integration\Saga;

use App\Ordering\Application\Command\MarkOrderManualReview\MarkOrderManualReviewCommand;
use App\Ordering\Application\Command\MarkOrderManualReview\MarkOrderManualReviewHandler;
use App\Ordering\Application\OrderMutator;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Domain\FailureReason;
use App\Shared\Domain\Identifier\PaymentId;
use App\Shared\Domain\Identifier\ReservationId;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Support\Fake\RecordingLogger;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;

/**
 * The record an operator works from (EC-47): the status, the `OrderNeedsManualReview` event with its reason and step,
 * and a `critical` log line. A repeated activity writes nothing more.
 */
#[Group('M6.3')]
final class ManualReviewTest extends IntegrationTestCase
{
    public function testCompensationThatCannotFinishLeavesAnOperatorRecordOnce(): void
    {
        $order = $this->placeOrder()
            ->markKeyReserved(ReservationId::fromString(self::uuid()), $this->t0->modify('+1 second'))
            ->startCompensation(FailureReason::ReservationExpired, $this->t0->modify('+2 seconds'));
        $this->infra->orders->save($order);
        $logger = new RecordingLogger();
        $handler = new MarkOrderManualReviewHandler(new OrderMutator($this->infra->orders, $this->infra->transactions, new MockClock($this->t0->modify('+1 hour'))), $logger);
        $command = new MarkOrderManualReviewCommand($order->id->value, FailureReason::CompensationFailed, 'ConsumeReservation');

        $handler($command);
        $handler($command); // the activity may run twice

        $stored = $this->infra->orders->get($order->id);
        self::assertSame(OrderStatus::ManualReview, $stored->status);
        self::assertSame(FailureReason::CompensationFailed, $stored->failureReason);
        self::assertSame(1, $this->countRows('outbox_messages', "event_type = 'OrderNeedsManualReview'"));
        self::assertTrue($logger->hasRecord('critical'));
    }

    /**
     * M6.4: a step failed for a non-business reason while the order was mid-saga (here `Paid`): the order goes straight
     * to `ManualReview` with the `SagaInterrupted` reason, one event, one critical log; a repeated activity changes nothing.
     */
    #[Group('M6.4')]
    public function testMidSagaOrderIsParkedWithoutCompensation(): void
    {
        $order = $this->placeOrder()
            ->markKeyReserved(ReservationId::fromString(self::uuid()), $this->t0->modify('+1 second'))
            ->markPaid(PaymentId::fromString(self::uuid()), $this->t0->modify('+2 seconds'));
        $this->infra->orders->save($order);
        $logger = new RecordingLogger();
        $handler = new MarkOrderManualReviewHandler(new OrderMutator($this->infra->orders, $this->infra->transactions, new MockClock($this->t0->modify('+1 hour'))), $logger);
        $command = new MarkOrderManualReviewCommand($order->id->value, FailureReason::SagaInterrupted, 'ConsumeReservation');

        $handler($command);
        $handler($command);

        $stored = $this->infra->orders->get($order->id);
        self::assertSame(OrderStatus::ManualReview, $stored->status);
        self::assertSame(FailureReason::SagaInterrupted, $stored->failureReason);
        self::assertSame(1, $this->countRows('outbox_messages', "event_type = 'OrderNeedsManualReview'"));
        self::assertTrue($logger->hasRecord('critical'));
    }
}
