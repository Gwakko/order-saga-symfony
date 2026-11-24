<?php

declare(strict_types=1);

namespace App\Tests\Workflow;

use App\Inventory\Application\Exception\KeyIsRevoked;
use App\Inventory\Application\Exception\OutOfStock;
use App\Inventory\Domain\Exception\ReservationHasExpired;
use App\Payment\Application\Exception\CircuitOpen;
use App\Payment\Application\Exception\PaymentDeclined;
use App\Payment\Application\Exception\PaymentOutcomeUnknown;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Exception\CurrencyMismatch;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Identifier\ProductKeyId;
use App\Shared\Domain\Identifier\ReservationId;
use App\Shared\Infrastructure\Id\SymfonyUuidGenerator;
use PHPUnit\Framework\Attributes\Group;
use Temporal\Workflow\WorkflowRunInterface;

/**
 * Business failures of the saga: which steps run, which compensations, and the reason the order ends with.
 * The activity mocks fail with the real exception classes, the way the use cases do.
 */
#[Group('M6.2')]
final class OrderFulfillmentFailureTest extends OrderSagaWorkflowTestCase
{
    /** EC-14, EC-48: nothing was done, so there is nothing to compensate. */
    #[Group('T-W-02')]
    public function testOutOfStockOnTheFirstStepFailsTheOrderWithoutCompensation(): void
    {
        $this->mockHappyPath();
        $this->activityMocks->expectFailure('ReserveKey', new OutOfStock(ProductId::fromString($this->uuid())));

        $run = $this->startSaga();

        self::assertSame('Failed', $this->resultOf($run));
        self::assertSame(['ReserveKey', 'FailOrder'], $this->scheduledActivities($run));
    }

    /** EC-16: the charge went through, the reservation expired; refund, release, Failed(ReservationExpired). */
    #[Group('T-W-03')]
    public function testReservationExpiredOnConsumeRefundsAndReleases(): void
    {
        $this->mockHappyPath();
        $this->activityMocks->expectFailure('ConsumeReservation', new ReservationHasExpired(ReservationId::fromString($this->uuid())));

        $run = $this->startSaga();

        self::assertSame('Failed', $this->resultOf($run));
        self::assertSame(
            ['ReserveKey', 'MarkOrderKeyReserved', 'ChargePayment', 'MarkOrderPaid', 'ConsumeReservation', 'StartOrderCompensation', 'RefundPayment', 'ReleaseReservation', 'FinishOrderCompensation'],
            $this->scheduledActivities($run),
        );
        self::assertSame('ReservationExpired', $this->compensationReason($run));
    }

    /** EC-18 */
    #[Group('T-W-04')]
    public function testRevokedKeyRefundsAndReleases(): void
    {
        $this->mockHappyPath();
        $this->activityMocks->expectFailure('ConsumeReservation', new KeyIsRevoked(ProductKeyId::fromString($this->uuid())));

        $run = $this->startSaga();

        self::assertSame('Failed', $this->resultOf($run));
        self::assertSame(
            ['ReserveKey', 'MarkOrderKeyReserved', 'ChargePayment', 'MarkOrderPaid', 'ConsumeReservation', 'StartOrderCompensation', 'RefundPayment', 'ReleaseReservation', 'FinishOrderCompensation'],
            $this->scheduledActivities($run),
        );
        self::assertSame('KeyRevoked', $this->compensationReason($run));
    }

    /**
     * EC-27: the first attempt of the charge fails with an unknown outcome (timeout), the retry succeeds. The charge is
     * scheduled once (retries are not new history events) and the saga goes on to completion.
     */
    #[Group('T-W-05')]
    public function testChargeSucceedsOnRetryAfterAnUnknownOutcome(): void
    {
        $this->mockHappyPath();
        $this->activityMocks->expectFailure('ChargePayment', new PaymentOutcomeUnknown());

        // Real time for this test: with skipping on, the server would burn through all retries before the mock is swapped.
        $this->testingService->lockTimeSkipping();

        try {
            $run = $this->startSaga();
            $this->assertRetriedThenCompleted($run);
        } finally {
            $this->testingService->unlockTimeSkipping();
        }
    }

    /**
     * @param WorkflowRunInterface<mixed> $run
     */
    private function assertRetriedThenCompleted(WorkflowRunInterface $run): void
    {
        for ($poll = 0; $poll < 150 && $this->pendingActivityAttempt($run) < 2; ++$poll) { // up to 30 s
            usleep(200_000);
        }
        self::assertGreaterThanOrEqual(2, $this->pendingActivityAttempt($run), 'The charge must have been retried.');

        $this->activityMocks->expectCompletion('ChargePayment', 'payment-1');

        self::assertSame('Completed', $this->resultOf($run));
        self::assertSame(
            ['ReserveKey', 'MarkOrderKeyReserved', 'ChargePayment', 'MarkOrderPaid', 'ConsumeReservation', 'CompleteOrder'],
            $this->scheduledActivities($run),
        );
    }

    /** EC-29: a declined payment is not retried; RefundPayment was registered before the charge, so it runs (and is a no-op in the use case), then the reservation is released. */
    #[Group('T-W-06')]
    public function testDeclinedPaymentCompensatesAndFailsWithPaymentDeclined(): void
    {
        $this->mockHappyPath();
        $this->activityMocks->expectFailure('ChargePayment', new PaymentDeclined(OrderId::fromString($this->uuid()), 'insufficient funds'));

        $run = $this->startSaga();

        self::assertSame('Failed', $this->resultOf($run));
        self::assertSame(
            ['ReserveKey', 'MarkOrderKeyReserved', 'ChargePayment', 'StartOrderCompensation', 'RefundPayment', 'ReleaseReservation', 'FinishOrderCompensation'],
            $this->scheduledActivities($run),
        );
        self::assertSame('PaymentDeclined', $this->compensationReason($run));
    }

    /**
     * EC-34: the circuit breaker stays open for the whole ScheduleToClose of the charge (5 minutes, skipped by the
     * time-skipping test server): the retries are exhausted, the saga compensates and fails with PaymentUnavailable.
     */
    #[Group('T-W-07')]
    public function testBreakerOpenForTheWholeStepFailsWithPaymentUnavailable(): void
    {
        $this->mockHappyPath();
        $this->activityMocks->expectFailure('ChargePayment', new CircuitOpen());

        $run = $this->startSaga();

        self::assertSame('Failed', $this->resultOf($run));
        self::assertSame(
            ['ReserveKey', 'MarkOrderKeyReserved', 'ChargePayment', 'StartOrderCompensation', 'RefundPayment', 'ReleaseReservation', 'FinishOrderCompensation'],
            $this->scheduledActivities($run),
        );
        self::assertSame('PaymentUnavailable', $this->compensationReason($run));
    }

    /** EC-36: the gateway charged in another currency; refund and release, Failed(CurrencyMismatch). */
    #[Group('T-W-08')]
    public function testCurrencyMismatchRefundsAndFailsWithCurrencyMismatch(): void
    {
        $this->mockHappyPath();
        $this->activityMocks->expectFailure('ChargePayment', new CurrencyMismatch(Currency::EUR, Currency::USD));

        $run = $this->startSaga();

        self::assertSame('Failed', $this->resultOf($run));
        self::assertSame(
            ['ReserveKey', 'MarkOrderKeyReserved', 'ChargePayment', 'StartOrderCompensation', 'RefundPayment', 'ReleaseReservation', 'FinishOrderCompensation'],
            $this->scheduledActivities($run),
        );
        self::assertSame('CurrencyMismatch', $this->compensationReason($run));
    }

    private function uuid(): string
    {
        return (new SymfonyUuidGenerator())->next();
    }
}
