<?php

declare(strict_types=1);

namespace App\Tests\Workflow;

use PHPUnit\Framework\Attributes\Group;

/**
 * Cancellation at each point of the saga (03_saga.md section 5). The flag in the database is mocked through
 * `IsCancellationRequested`; the signal is sent to the real running workflow while one of its activities is retrying
 * (real time for those tests, like T-W-05).
 */
#[Group('M6.3')]
final class OrderCancellationTest extends OrderSagaWorkflowTestCase
{
    /** EC-38: the flag is already set when the saga starts: Pending -> Cancelled, no reservation, no payment. */
    #[Group('T-W-09')]
    public function testCancellationBeforeTheSagaStartsCancelsWithoutAnyStep(): void
    {
        $this->mockHappyPath();
        $this->activityMocks->expectConsecutiveCompletions('IsCancellationRequested', [true]);

        $run = $this->startSaga();

        self::assertSame('Cancelled', $this->resultOf($run));
        self::assertSame(['CancelOrderBeforeStart'], $this->scheduledActivities($run));
        self::assertSame(1, $this->guardChecks($run));
    }

    /** EC-39, flag path: set after the reservation (the signal never arrived). Release, then Cancelled; no refund. */
    #[Group('T-W-10')]
    public function testCancellationFlagAfterTheReservationReleasesIt(): void
    {
        $this->mockHappyPath();
        $this->activityMocks->expectConsecutiveCompletions('IsCancellationRequested', [false, true]);
        $this->activityMocks->expectCompletion('FinishOrderCompensation', 'Cancelled');

        $run = $this->startSaga();

        self::assertSame('Cancelled', $this->resultOf($run));
        self::assertSame(
            ['ReserveKey', 'MarkOrderKeyReserved', 'StartOrderCompensation', 'ReleaseReservation', 'FinishOrderCompensation'],
            $this->scheduledActivities($run),
        );
        self::assertSame('CancelledByCustomer', $this->compensationReason($run));
    }

    /** EC-39, signal path: the signal arrives while `MarkOrderKeyReserved` is retrying. */
    #[Group('T-W-10')]
    public function testCancellationSignalAfterTheReservationReleasesIt(): void
    {
        $this->mockHappyPath();
        $this->activityMocks->expectCompletion('FinishOrderCompensation', 'Cancelled');
        $this->activityMocks->expectFailure('MarkOrderKeyReserved', new \RuntimeException('database is down'));

        $this->testingService->lockTimeSkipping();

        try {
            $run = $this->startSaga();
            $this->waitForAttempt($run, 2);
            $this->signalCancellation($run);
            $this->activityMocks->expectCompletion('MarkOrderKeyReserved', 2);

            self::assertSame('Cancelled', $this->resultOf($run));
            self::assertSame(
                ['ReserveKey', 'MarkOrderKeyReserved', 'StartOrderCompensation', 'ReleaseReservation', 'FinishOrderCompensation'],
                $this->scheduledActivities($run),
            );
            self::assertSame(1, $this->guardChecks($run), 'The signal answers the second guard without a database read.');
        } finally {
            $this->testingService->unlockTimeSkipping();
        }
    }

    /** EC-40: the signal arrives while the charge is running; the charge completes, then refund and release. */
    #[Group('T-W-11')]
    public function testCancellationDuringTheChargeRefundsAndReleases(): void
    {
        $this->mockHappyPath();
        $this->activityMocks->expectCompletion('FinishOrderCompensation', 'Cancelled');
        $this->activityMocks->expectFailure('ChargePayment', new \RuntimeException('gateway is slow'));

        $this->testingService->lockTimeSkipping();

        try {
            $run = $this->startSaga();
            $this->waitForAttempt($run, 2);
            $this->signalCancellation($run);
            $this->activityMocks->expectCompletion('ChargePayment', 'payment-1');

            self::assertSame('Cancelled', $this->resultOf($run));
            self::assertSame(
                ['ReserveKey', 'MarkOrderKeyReserved', 'ChargePayment', 'MarkOrderPaid', 'StartOrderCompensation', 'RefundPayment', 'ReleaseReservation', 'FinishOrderCompensation'],
                $this->scheduledActivities($run),
            );
            self::assertSame('CancelledByCustomer', $this->compensationReason($run));
        } finally {
            $this->testingService->unlockTimeSkipping();
        }
    }

    /** EC-41: after `ConsumeReservation` the saga ignores the signal: Completed, no refund, no release. */
    #[Group('T-W-12')]
    public function testCancellationAfterConsumingTheReservationIsIgnored(): void
    {
        $this->mockHappyPath();
        $this->activityMocks->expectFailure('CompleteOrder', new \RuntimeException('database is down'));

        $this->testingService->lockTimeSkipping();

        try {
            $run = $this->startSaga();
            $this->waitForAttempt($run, 2);
            $this->signalCancellation($run);
            $this->activityMocks->expectCompletion('CompleteOrder', 4);

            self::assertSame('Completed', $this->resultOf($run));
            self::assertSame(
                ['ReserveKey', 'MarkOrderKeyReserved', 'ChargePayment', 'MarkOrderPaid', 'ConsumeReservation', 'CompleteOrder'],
                $this->scheduledActivities($run),
            );
        } finally {
            $this->testingService->unlockTimeSkipping();
        }
    }

    /** EC-43: two signals, one cancellation: the compensation starts once. */
    #[Group('T-W-13')]
    public function testDoubleCancellationCompensatesOnce(): void
    {
        $this->mockHappyPath();
        $this->activityMocks->expectCompletion('FinishOrderCompensation', 'Cancelled');
        $this->activityMocks->expectFailure('MarkOrderKeyReserved', new \RuntimeException('database is down'));

        $this->testingService->lockTimeSkipping();

        try {
            $run = $this->startSaga();
            $this->waitForAttempt($run, 2);
            $this->signalCancellation($run);
            $this->signalCancellation($run);
            $this->activityMocks->expectCompletion('MarkOrderKeyReserved', 2);

            self::assertSame('Cancelled', $this->resultOf($run));
            self::assertSame(
                ['ReserveKey', 'MarkOrderKeyReserved', 'StartOrderCompensation', 'ReleaseReservation', 'FinishOrderCompensation'],
                $this->scheduledActivities($run),
            );
            self::assertCount(1, array_keys($this->scheduledActivities($run), 'StartOrderCompensation', true));
        } finally {
            $this->testingService->unlockTimeSkipping();
        }
    }
}
