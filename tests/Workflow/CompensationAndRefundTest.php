<?php

declare(strict_types=1);

namespace App\Tests\Workflow;

use App\Inventory\Application\Exception\ReservationNotFound;
use App\Inventory\Domain\Exception\ReservationHasExpired;
use App\Payment\Application\Exception\PaymentGatewayUnavailable;
use App\Saga\Infrastructure\Temporal\TemporalClientFactory;
use App\Saga\Infrastructure\Temporal\TemporalOrderRefundStarter;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\ReservationId;
use App\Shared\Infrastructure\Id\SymfonyUuidGenerator;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Retried and failed compensations, and `RefundWorkflow` (03_saga.md sections 4 and 7, EC-45, EC-47, EC-59, EC-61).
 */
#[Group('M6.3')]
final class CompensationAndRefundTest extends OrderSagaWorkflowTestCase
{
    private const array COMPENSATED_AFTER_CONSUME = ['ReserveKey', 'MarkOrderKeyReserved', 'ChargePayment', 'MarkOrderPaid', 'ConsumeReservation', 'StartOrderCompensation', 'RefundPayment', 'ReleaseReservation', 'FinishOrderCompensation'];

    /** EC-45: the refund fails twice (gateway 503), the third attempt succeeds; one scheduled activity, the saga ends Failed. */
    #[Group('T-W-14')]
    public function testRefundCompensationIsRetriedUntilItSucceeds(): void
    {
        $this->mockHappyPath();
        $this->activityMocks->expectFailure('ConsumeReservation', new ReservationHasExpired(ReservationId::fromString($this->uuid())));
        $this->activityMocks->expectFailure('RefundPayment', new PaymentGatewayUnavailable('HTTP 503'));

        $this->testingService->lockTimeSkipping();

        try {
            $run = $this->startSaga();
            $this->waitForAttempt($run, 3); // 5 s and 10 s of backoff in real time
            $this->activityMocks->expectCompletion('RefundPayment', 'Refunded');

            self::assertSame('Failed', $this->resultOf($run));
            self::assertSame(self::COMPENSATED_AFTER_CONSUME, $this->scheduledActivities($run), 'Retries are attempts of one scheduled activity.');
            self::assertSame('ReservationExpired', $this->compensationReason($run));
        } finally {
            $this->testingService->unlockTimeSkipping();
        }
    }

    /**
     * EC-47: the refund never succeeds. After its ScheduleToClose (7 days, skipped by the test server) the saga marks the
     * order for manual review and ends without an exception; the reservation release does not run behind a failed refund.
     */
    #[Group('T-W-15')]
    public function testRefundThatNeverSucceedsParksTheOrderForManualReview(): void
    {
        $this->mockHappyPath();
        $this->activityMocks->expectFailure('ConsumeReservation', new ReservationHasExpired(ReservationId::fromString($this->uuid())));
        $this->activityMocks->expectFailure('RefundPayment', new PaymentGatewayUnavailable('HTTP 503'));

        $run = $this->startSaga();

        self::assertSame('ManualReview', $this->resultOf($run));
        self::assertSame(
            ['ReserveKey', 'MarkOrderKeyReserved', 'ChargePayment', 'MarkOrderPaid', 'ConsumeReservation', 'StartOrderCompensation', 'RefundPayment', 'MarkOrderManualReview'],
            $this->scheduledActivities($run),
        );
    }

    /** EC-59: key revoked, money returned, order Refunded. */
    #[Group('T-W-16')]
    public function testRefundWorkflowRevokesRefundsAndFinishes(): void
    {
        $this->mockHappyPath();

        $run = $this->startRefund();

        self::assertSame('Refunded', $this->resultOf($run));
        self::assertSame(['RevokeSoldKey', 'RefundPayment', 'FinishOrderRefund'], $this->activityNamesOf($run->getExecution()));
    }

    /** A step that cannot be done (here: the reservation of the order is gone) parks the refund for an operator. */
    public function testRefundWorkflowParksTheOrderWhenAStepFailsForGood(): void
    {
        $this->mockHappyPath();
        $this->activityMocks->expectFailure('RevokeSoldKey', new ReservationNotFound(OrderId::fromString($this->uuid())));

        $run = $this->startRefund();

        self::assertSame('ManualReview', $this->resultOf($run));
        self::assertSame(['RevokeSoldKey', 'MarkOrderManualReview'], $this->activityNamesOf($run->getExecution()));
    }

    /** EC-61, INV-16: a second start (while running and after it finished) is success and creates no second run. */
    #[Group('T-W-17')]
    public function testDuplicateRefundStartLeavesOneRun(): void
    {
        $this->mockHappyPath();
        $orderId = (new SymfonyUuidGenerator())->next();
        $starter = new TemporalOrderRefundStarter(new TemporalClientFactory(WorkflowTestEnvironment::ADDRESS), self::configuration(), new NullLogger());

        $starter->start(OrderId::fromString($orderId));
        $starter->start(OrderId::fromString($orderId)); // while the first run may still be running

        $stub = $this->workflowClient->newUntypedRunningWorkflowStub('refund-' . $orderId);
        self::assertSame('Refunded', $stub->getResult('string'));

        $starter->start(OrderId::fromString($orderId)); // after it finished: reject duplicate, still success

        $execution = $stub->describe()->info->execution;
        self::assertSame(['RevokeSoldKey', 'RefundPayment', 'FinishOrderRefund'], $this->activityNamesOf($execution), 'One run, each step once.');
    }
}
