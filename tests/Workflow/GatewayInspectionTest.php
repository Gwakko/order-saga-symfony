<?php

declare(strict_types=1);

namespace App\Tests\Workflow;

use App\Payment\Application\Exception\CircuitOpen;
use App\Payment\Application\Exception\PaymentOutcomeUnknown;
use App\Payment\Application\Exception\ReservationNotActive;
use App\Shared\Domain\Identifier\OrderId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Temporal\Api\Enums\V1\TimeoutType;
use Temporal\DataConverter\EncodedValues;
use Temporal\Exception\Failure\ApplicationFailure;
use Temporal\Exception\Failure\TimeoutFailure;
use Temporal\Workflow\WorkflowRunInterface;

/**
 * The gateway-inspection branch after `ChargePayment` gave up (docs/spec/09_go_worker.md section 2.3). The Go worker is
 * replaced by mocks of `InspectGatewayCharge` on a second test worker (`payment-go-test`); the answers are the golden
 * files of contracts/inspect-gateway-charge/v1.
 *
 * Two ways to make the first charge fail: `CircuitOpen` on every attempt (the retries run out when the test server skips
 * time, the same as T-W-07) when the repeated charge is not needed, and a single non-retryable failure answered through
 * `$this->sequences` when the repeated `ChargePayment` must then succeed (the mock cannot tell the two calls apart).
 */
#[Group('M9.4')]
final class GatewayInspectionTest extends OrderSagaWorkflowTestCase
{
    private const array CHARGE_THEN_STOP = ['ReserveKey', 'MarkOrderKeyReserved', 'ChargePayment', 'PrepareChargeInspection', 'InspectGatewayCharge'];
    private const array COMPENSATION = ['StartOrderCompensation', 'RefundPayment', 'ReleaseReservation', 'FinishOrderCompensation'];

    /** EC-107, T-W-23: the switch is off (the mock of PrepareChargeInspection answers `inspect=false`): behavior of T-W-07, Go not called. */
    #[Group('T-W-23')]
    public function testSwitchOffKeepsThePreM9Outcome(): void
    {
        $this->mockHappyPath();
        $this->activityMocks->expectFailure('ChargePayment', new CircuitOpen());

        $run = $this->startSaga();

        self::assertSame('Failed', $this->resultOf($run));
        self::assertSame(
            ['ReserveKey', 'MarkOrderKeyReserved', 'ChargePayment', 'PrepareChargeInspection', ...self::COMPENSATION],
            $this->scheduledActivities($run),
        );
        self::assertSame('PaymentUnavailable', $this->compensationReason($run));
    }

    /** T-W-24: the gateway holds the charge for the expected amount: the repeated ChargePayment confirms it, the order completes, no refund. */
    #[Group('T-W-24')]
    public function testSucceededChargeIsConfirmedAndTheOrderCompletes(): void
    {
        $this->mockInspectedCharge(self::golden('succeeded'));

        $run = $this->startSaga();

        self::assertSame('Completed', $this->resultOf($run));
        self::assertSame(
            [...self::CHARGE_THEN_STOP, 'ChargePayment', 'MarkOrderPaid', 'ConsumeReservation', 'CompleteOrder'],
            $this->scheduledActivities($run),
        );
        self::assertNotContains('RefundPayment', $this->scheduledActivities($run));
    }

    /** EC-99, T-W-25: a charge of another amount is not confirmed: pre-M9 behavior (RefundPayment finds and returns it). */
    #[Group('T-W-25')]
    public function testChargeOfAnotherAmountKeepsThePreM9Outcome(): void
    {
        $this->mockExhaustedCharge(self::golden('mismatch'));

        $run = $this->startSaga();

        self::assertSame('Failed', $this->resultOf($run));
        self::assertSame([...self::CHARGE_THEN_STOP, ...self::COMPENSATION], $this->scheduledActivities($run));
        self::assertSame('PaymentUnavailable', $this->compensationReason($run));
    }

    /** EC-100, T-W-26: the gateway proved the decline: Failed(PaymentDeclined) with the compensations. */
    #[Group('T-W-26')]
    public function testDeclinedChargeFailsTheOrderWithPaymentDeclined(): void
    {
        $this->mockExhaustedCharge(self::golden('declined'));

        $run = $this->startSaga();

        self::assertSame('Failed', $this->resultOf($run));
        self::assertSame([...self::CHARGE_THEN_STOP, ...self::COMPENSATION], $this->scheduledActivities($run));
        self::assertSame('PaymentDeclined', $this->compensationReason($run));
    }

    /** T-W-27: the gateway never saw the charge: pre-M9 behavior. */
    #[Group('T-W-27')]
    public function testChargeNotFoundKeepsThePreM9Outcome(): void
    {
        $this->mockExhaustedCharge(self::golden('not_found'));

        $run = $this->startSaga();

        self::assertSame('Failed', $this->resultOf($run));
        self::assertSame([...self::CHARGE_THEN_STOP, ...self::COMPENSATION], $this->scheduledActivities($run));
        self::assertSame('PaymentUnavailable', $this->compensationReason($run));
    }

    /**
     * EC-93, EC-94, EC-98, T-W-28: any failure of the Go activity (each error type of errors.json, and a ScheduleToStart
     * timeout, which is what a missing Go worker produces) leaves the pre-M9 behavior.
     */
    #[Group('T-W-28')]
    #[DataProvider('goFailures')]
    public function testAnyFailureOfTheGoActivityKeepsThePreM9Outcome(\Throwable $failure): void
    {
        $this->mockExhaustedCharge(null);
        $this->activityMocks->expectFailure('InspectGatewayCharge', $failure);

        $run = $this->startSaga();

        self::assertSame('Failed', $this->resultOf($run));
        self::assertSame([...self::CHARGE_THEN_STOP, ...self::COMPENSATION], $this->scheduledActivities($run));
        self::assertSame('PaymentUnavailable', $this->compensationReason($run));
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function goFailures(): iterable
    {
        $errors = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/contracts/inspect-gateway-charge/v1/errors.json'), true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($errors) && is_array($errors['errors']));

        foreach ($errors['errors'] as $error) {
            assert(is_array($error) && is_string($error['type']) && is_bool($error['nonRetryable']));
            yield $error['type'] => [new ApplicationFailure('go: ' . $error['type'], $error['type'], $error['nonRetryable'])];
        }

        yield 'ScheduleToStart timeout (no Go worker)' => [new TimeoutFailure('ScheduleToStart', EncodedValues::empty(), TimeoutType::TIMEOUT_TYPE_SCHEDULE_TO_START)];
    }

    /**
     * EC-103, T-W-29: an answer that breaks the contract (a charge for `not_found`, an unknown contract version, a money
     * amount as a float, no object at all) is treated like a failure of the activity.
     *
     * @param array<mixed> $answer
     */
    #[Group('T-W-29')]
    #[DataProvider('contractViolations')]
    public function testAnswerThatBreaksTheContractKeepsThePreM9Outcome(array $answer): void
    {
        $this->mockExhaustedCharge($answer);

        $run = $this->startSaga();

        self::assertSame('Failed', $this->resultOf($run));
        self::assertSame([...self::CHARGE_THEN_STOP, ...self::COMPENSATION], $this->scheduledActivities($run));
        self::assertSame('PaymentUnavailable', $this->compensationReason($run));
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function contractViolations(): iterable
    {
        $notFound = self::golden('not_found');
        $succeeded = self::golden('succeeded');

        yield 'charge for not_found' => [['charge' => $succeeded['charge']] + $notFound];
        yield 'contract version 2' => [['contractVersion' => 2] + $succeeded];
        yield 'float amount' => [['charge' => ['amount' => 19.99] + (is_array($succeeded['charge']) ? $succeeded['charge'] : [])] + $succeeded];
        yield 'unknown outcome' => [['outcome' => 'refunded'] + $succeeded];
        yield 'empty object' => [[]];
    }

    /** EC-111, T-W-30: the repeated charge finds the reservation gone: Failed(ReservationExpired) with the compensations. */
    #[Group('T-W-30')]
    public function testReservationExpiredDuringRecoveryFailsWithReservationExpired(): void
    {
        $this->mockInspectedCharge(self::golden('succeeded'), new ReservationNotActive(OrderId::fromString($this->uuid())));

        $run = $this->startSaga();

        self::assertSame('Failed', $this->resultOf($run));
        self::assertSame([...self::CHARGE_THEN_STOP, 'ChargePayment', ...self::COMPENSATION], $this->scheduledActivities($run));
        self::assertSame('ReservationExpired', $this->compensationReason($run));
    }

    /**
     * EC-110, T-W-31: the customer cancels while the inspection is running. The charge is confirmed, the guard before
     * `ConsumeReservation` sees the signal, so the saga refunds and releases: Cancelled.
     */
    #[Group('T-W-31')]
    public function testCancellationDuringTheInspectionRefundsTheConfirmedCharge(): void
    {
        $this->mockInspectedCharge(null);
        $this->activityMocks->expectFailure('InspectGatewayCharge', new \RuntimeException('Go worker is slow'));
        $this->activityMocks->expectCompletion('FinishOrderCompensation', 'Cancelled');

        $this->testingService->lockTimeSkipping();

        try {
            $run = $this->startSaga();
            $this->waitForAttempt($run, 2);
            $this->signalCancellation($run);
            $this->activityMocks->expectCompletion('InspectGatewayCharge', self::golden('succeeded'));

            self::assertSame('Cancelled', $this->resultOf($run));
            self::assertSame(
                [...self::CHARGE_THEN_STOP, 'ChargePayment', 'MarkOrderPaid', ...self::COMPENSATION],
                $this->scheduledActivities($run),
            );
            self::assertSame('CancelledByCustomer', $this->compensationReason($run));
        } finally {
            $this->testingService->unlockTimeSkipping();
        }
    }

    /** T-W-32: PrepareChargeInspection keeps failing (database down): pre-M9 behavior, Go not called. */
    #[Group('T-W-32')]
    public function testFailingPrepareStepKeepsThePreM9Outcome(): void
    {
        $this->mockHappyPath();
        $this->activityMocks->expectFailure('ChargePayment', new CircuitOpen());
        $this->activityMocks->expectFailure('PrepareChargeInspection', new \RuntimeException('database is down'));

        $run = $this->startSaga();

        self::assertSame('Failed', $this->resultOf($run));
        self::assertSame(
            ['ReserveKey', 'MarkOrderKeyReserved', 'ChargePayment', 'PrepareChargeInspection', ...self::COMPENSATION],
            $this->scheduledActivities($run),
        );
        self::assertSame('PaymentUnavailable', $this->compensationReason($run));
    }

    /**
     * Not part of the suite (`make wf-record-history FILTER=testRecordChargeInspectedHistory`). Records the branch with the
     * switch on and a confirmed charge, written by the workflow WITH the `gateway-inspection` marker. Re-record only after an
     * intentional change of the branch; `history-happy.json` and `history-charge-exhausted-v0.json` are never re-recorded.
     */
    public function testRecordChargeInspectedHistory(): void
    {
        if (getenv('RECORD_HISTORY') !== '1') {
            self::markTestSkipped('Set RECORD_HISTORY=1 (make wf-record-history FILTER=...) to record tests/Workflow/Fixtures/history-charge-inspected-v1.json.');
        }

        $this->mockInspectedCharge(self::golden('succeeded'));
        $run = $this->startSaga(OrderFulfillmentReplayTest::INSPECTED_V1_WORKFLOW_ID);
        self::assertSame('Completed', $this->resultOf($run));

        (new \Temporal\Testing\Replay\WorkflowReplayer())->downloadHistory(
            \App\Saga\Infrastructure\Temporal\Workflow\OrderFulfillmentWorkflow::TYPE,
            $run->getExecution(),
            OrderFulfillmentReplayTest::INSPECTED_V1_FIXTURE,
        );

        self::assertFileExists(OrderFulfillmentReplayTest::INSPECTED_V1_FIXTURE);
    }

    /**
     * The switch is on; the first `ChargePayment` fails once and for good (so it is called exactly once), the repeated one
     * answers with `$recovery` (a payment id when it is null, a failure when a Throwable).
     * `$inspection` is the answer of the Go activity (null: the test sets it).
     *
     * @param ?array<mixed> $inspection
     */
    private function mockInspectedCharge(?array $inspection, ?\Throwable $recovery = null): void
    {
        $this->mockHappyPath();
        $this->mockPreparedPayment();
        $this->sequences->expectSequence('ChargePayment', [
            new ApplicationFailure('the gateway did not answer', PaymentOutcomeUnknown::class, true),
            $recovery ?? 'payment-1',
        ]);

        if ($inspection !== null) {
            $this->activityMocks->expectCompletion('InspectGatewayCharge', $inspection);
        }
    }

    /**
     * The switch is on and every attempt of the charge ends with an open breaker until the retries run out (EC-34).
     *
     * @param ?array<mixed> $inspection
     */
    private function mockExhaustedCharge(?array $inspection): void
    {
        $this->mockHappyPath();
        $this->mockPreparedPayment();
        $this->activityMocks->expectFailure('ChargePayment', new CircuitOpen());

        if ($inspection !== null) {
            $this->activityMocks->expectCompletion('InspectGatewayCharge', $inspection);
        }
    }

    private function mockPreparedPayment(): void
    {
        $this->activityMocks->expectCompletion('PrepareChargeInspection', ['inspect' => true, 'paymentId' => '0190a5c4-0000-7000-8000-0000000000a1', 'amount' => 1999, 'currency' => 'EUR']);
    }

    /** @return array<mixed> a golden answer of contracts/inspect-gateway-charge/v1 */
    private static function golden(string $name): array
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/contracts/inspect-gateway-charge/v1/output.' . $name . '.json'), true, 512, JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : throw new \UnexpectedValueException('A golden file is not an object.');
    }
}
