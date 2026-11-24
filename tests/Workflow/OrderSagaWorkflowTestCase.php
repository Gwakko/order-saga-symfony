<?php

declare(strict_types=1);

namespace App\Tests\Workflow;

use App\Kernel;
use App\Saga\Infrastructure\Temporal\Config\SagaConfiguration;
use App\Saga\Infrastructure\Temporal\Workflow\OrderFulfillmentWorkflow;
use App\Saga\Infrastructure\Temporal\Workflow\RefundWorkflow;
use App\Shared\Infrastructure\Id\SymfonyUuidGenerator;
use Temporal\Api\Enums\V1\EventType;
use Temporal\DataConverter\DataConverter;
use Temporal\DataConverter\EncodedValues;
use Temporal\Api\Workflowservice\V1\DescribeWorkflowExecutionRequest;
use Temporal\Testing\WorkflowTestCase;
use Temporal\Workflow\WorkflowExecution;
use Temporal\Workflow\WorkflowRunInterface;

/**
 * Real `OrderFulfillmentWorkflow` on `temporal-test-server`; every activity answers from a mock (ActivityMocker), so
 * the tests check the orchestration (order of steps, compensations, failure reasons), not the use cases.
 */
abstract class OrderSagaWorkflowTestCase extends WorkflowTestCase
{
    private static ?SagaConfiguration $configuration = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        WorkflowTestEnvironment::ensureStarted();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->activityMocks->clear();
    }

    /** Every activity succeeds; a test overrides the ones it wants to break. */
    protected function mockHappyPath(): void
    {
        $this->activityMocks->expectCompletion('ReserveKey', 'reservation-1');
        $this->activityMocks->expectCompletion('MarkOrderKeyReserved', 2);
        $this->activityMocks->expectCompletion('ChargePayment', 'payment-1');
        $this->activityMocks->expectCompletion('MarkOrderPaid', 3);
        $this->activityMocks->expectCompletion('ConsumeReservation', 'key-1');
        $this->activityMocks->expectCompletion('CompleteOrder', 4);
        $this->activityMocks->expectCompletion('FailOrder', true);
        $this->activityMocks->expectCompletion('StartOrderCompensation', true);
        $this->activityMocks->expectCompletion('RefundPayment', 'Refunded');
        $this->activityMocks->expectCompletion('ReleaseReservation', true);
        $this->activityMocks->expectCompletion('FinishOrderCompensation', 'Failed');
        $this->activityMocks->expectCompletion('MarkOrderManualReview', true);
        $this->activityMocks->expectCompletion('IsCancellationRequested', false);
        $this->activityMocks->expectCompletion('CancelOrderBeforeStart', true);
        $this->activityMocks->expectCompletion('RevokeSoldKey', 'key-1');
        $this->activityMocks->expectCompletion('FinishOrderRefund', true);
    }

    protected function uuid(): string
    {
        return (new SymfonyUuidGenerator())->next();
    }

    /** @return WorkflowRunInterface<mixed> */
    protected function startSaga(?string $orderId = null): WorkflowRunInterface
    {
        $orderId ??= (new SymfonyUuidGenerator())->next();
        $options = self::configuration()->workflowOptions($orderId);
        $stub = $this->workflowClient->newUntypedWorkflowStub(OrderFulfillmentWorkflow::TYPE, $options);

        return $this->workflowClient->start($stub, $orderId);
    }

    /** @return WorkflowRunInterface<mixed> */
    protected function startRefund(?string $orderId = null): WorkflowRunInterface
    {
        $orderId ??= (new SymfonyUuidGenerator())->next();
        $stub = $this->workflowClient->newUntypedWorkflowStub(RefundWorkflow::TYPE, self::configuration()->refundWorkflowOptions($orderId));

        return $this->workflowClient->start($stub, $orderId);
    }

    /** @param WorkflowRunInterface<mixed> $run */
    protected function resultOf(WorkflowRunInterface $run): string
    {
        $result = $run->getResult('string');

        return is_string($result) ? $result : throw new \UnexpectedValueException('The workflow returned a non-string result.');
    }

    /**
     * Names of the activities the workflow scheduled, in order (a retried activity is scheduled once). The cancellation
     * guard (`IsCancellationRequested`, between the steps) is left out; see `guardChecks()` for it.
     *
     * @param WorkflowRunInterface<mixed> $run
     *
     * @return list<string>
     */
    protected function scheduledActivities(WorkflowRunInterface $run): array
    {
        return array_values(array_filter(
            $this->activityNamesOf($run->getExecution()),
            static fn(string $name): bool => $name !== 'IsCancellationRequested',
        ));
    }

    /**
     * How many times the workflow asked the database for the cancellation flag (the signal is checked without an activity).
     *
     * @param WorkflowRunInterface<mixed> $run
     */
    protected function guardChecks(WorkflowRunInterface $run): int
    {
        return count(array_filter($this->activityNamesOf($run->getExecution()), static fn(string $name): bool => $name === 'IsCancellationRequested'));
    }

    /**
     * Sends the `requestCancellation` signal to the run.
     *
     * @param WorkflowRunInterface<mixed> $run
     */
    protected function signalCancellation(WorkflowRunInterface $run): void
    {
        $this->workflowClient->newUntypedRunningWorkflowStub($run->getExecution()->getID())->signal('requestCancellation');
    }

    /**
     * Waits (real time, up to 30 s) until the activity that is retrying now has reached the given attempt.
     *
     * @param WorkflowRunInterface<mixed> $run
     */
    protected function waitForAttempt(WorkflowRunInterface $run, int $attempt): void
    {
        for ($poll = 0; $poll < 150 && $this->pendingActivityAttempt($run) < $attempt; ++$poll) {
            usleep(200_000);
        }

        self::assertGreaterThanOrEqual($attempt, $this->pendingActivityAttempt($run), sprintf('The activity must have reached attempt %d.', $attempt));
    }

    /**
     * Names of the activities scheduled so far by the run behind an execution (see scheduledActivities).
     *
     * @return list<string>
     */
    protected function activityNamesOf(WorkflowExecution $execution): array
    {
        $names = [];

        foreach ($this->workflowClient->getWorkflowHistory($execution)->getEvents() as $event) {
            if ($event->getEventType() === EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED) {
                $names[] = $event->getActivityTaskScheduledEventAttributes()?->getActivityType()?->getName() ?? '';
            }
        }

        return $names;
    }

    /**
     * The `FailureReason` the saga passed to `StartOrderCompensation` (second argument), read from the history.
     *
     * @param WorkflowRunInterface<mixed> $run
     */
    protected function compensationReason(WorkflowRunInterface $run): string
    {
        foreach ($this->workflowClient->getWorkflowHistory($run->getExecution())->getEvents() as $event) {
            $attributes = $event->getActivityTaskScheduledEventAttributes();

            if ($event->getEventType() === EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED && $attributes?->getActivityType()?->getName() === 'StartOrderCompensation') {
                $input = $attributes->getInput();
                $reason = $input === null ? null : EncodedValues::fromPayloads($input, DataConverter::createDefault())->getValue(1, 'string');

                return is_string($reason) ? $reason : throw new \UnexpectedValueException('Compensation reason is not a string.');
            }
        }

        throw new \LogicException('The workflow did not start a compensation.');
    }

    /**
     * Attempt number of the activity that is retrying now (0 when none is pending).
     *
     * @param WorkflowRunInterface<mixed> $run
     */
    protected function pendingActivityAttempt(WorkflowRunInterface $run): int
    {
        $request = (new DescribeWorkflowExecutionRequest())
            ->setNamespace('default')
            ->setExecution($run->getExecution()->toProtoWorkflowExecution());

        $response = $this->workflowClient->getServiceClient()->DescribeWorkflowExecution($request);

        foreach ($response->getPendingActivities() as $pending) {
            return $pending->getAttempt();
        }

        return 0;
    }

    protected static function configuration(): SagaConfiguration
    {
        if (self::$configuration === null) {
            $kernel = new Kernel('test', false);
            $kernel->boot();
            self::$configuration = $kernel->getContainer()->get(SagaConfiguration::class);
        }

        return self::$configuration;
    }
}
