<?php

declare(strict_types=1);

namespace App\Tests\Integration\GoWorker;

use App\Ordering\Domain\OrderStatus;
use App\Payment\Domain\PaymentStatus;
use App\Saga\Infrastructure\Temporal\Config\SagaConfiguration;
use App\Saga\Infrastructure\Temporal\TemporalClientFactory;
use App\Saga\Infrastructure\Temporal\TemporalOrderSagaStarter;
use App\Shared\Domain\Identifier\ProductId;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\Support\GatewayEmulator;
use App\Tests\Integration\Support\TemporalTestWorker;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\Enums\V1\TaskQueueType;
use Temporal\Api\Enums\V1\TimeoutType;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\Taskqueue\V1\TaskQueue;
use Temporal\Api\Workflowservice\V1\DescribeTaskQueueRequest;
use Temporal\Client\WorkflowClient;

/**
 * M9.5, docs/spec/09_go_worker.md section 9.4: the PHP saga (real PHP worker under RoadRunner, real activities, `app_test`)
 * against the real Go worker (compose profile `go`) and the gateway emulator. Run by `make test-go-e2e`
 * (tests/E2E/go-e2e.sh), which starts the Go worker on a queue of its own and exports `TEMPORAL_GO_TASK_QUEUE`; the group
 * `go-e2e` is excluded from `make test-int` and `make ci`.
 *
 * Getting `ChargePayment` to give up. The profile has ScheduleToClose 300 s (config/packages/temporal.yaml); five minutes per
 * scenario is too long and there is no time skipping against the dev server. The PHP worker of each scenario is started with
 * `TEMPORAL_E2E_CHARGE_SCHEDULE_TO_CLOSE=20` (see E2eSagaConfiguration): only the test configuration reads it, only a shorter
 * value is accepted, so the TTL invariant (T-CFG-01) still holds. The gateway answers `late-success:<ms>`: the charge exists
 * and is `pending` for `<ms>`, so every `ChargePayment` attempt ends with an unknown outcome until the deadline, and the
 * payment row stays `Unknown`, which is what `PrepareChargeInspection` looks for.
 */
#[Group('go-e2e')]
#[Group('M9.5')]
final class GoWorkerEndToEndTest extends IntegrationTestCase
{
    private const int CHARGE_DEADLINE_SECONDS = 20;
    /** The charge stays pending longer than ChargePayment is allowed to retry, and not much longer than the inspection retries. */
    private const int CHARGE_PENDING_MILLISECONDS = 32_000;
    private const int INSPECTION_SCHEDULE_TO_START_SECONDS = 20;

    private GatewayEmulator $emulator;
    private ?TemporalTestWorker $worker = null;
    /** @var non-empty-string|null */
    private ?string $workflowId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->emulator = GatewayEmulator::fromEnvironment();
        $this->emulator->reset();
    }

    protected function tearDown(): void
    {
        $this->worker?->stop();

        if ($this->workflowId !== null) {
            try {
                $this->client()->newUntypedRunningWorkflowStub($this->workflowId)->terminate('integration test cleanup');
            } catch (\Throwable) {
                // Already finished.
            }
        }

        $this->emulator->reset();
        parent::tearDown();
    }

    /**
     * T-M9-10 and T-M9-11. The first `ChargePayment` gives up after 20 s with an unknown outcome while the gateway holds a
     * charge it has not confirmed yet. The Go worker asks the gateway (`pending` for a few seconds, then `succeeded`), the
     * saga repeats `ChargePayment` (same idempotency key) and the order completes. The gateway moved the money once and
     * never refunded.
     */
    #[Group('T-M9-10')]
    #[Group('T-M9-11')]
    public function testInspectionByTheGoWorkerRecoversAnExhaustedCharge(): void
    {
        $goQueue = $this->goQueue();
        self::assertTrue($this->hasActivityPollers($goQueue), 'the Go worker polls ' . $goQueue . ' (start it with make test-go-e2e)');

        $order = $this->runSaga($goQueue, true, 150);

        // T-M9-10
        self::assertSame('Completed', $order['result'], $this->diagnostics());
        self::assertSame(OrderStatus::Completed->value, $order['orderStatus']);
        self::assertSame(PaymentStatus::Succeeded->value, $order['paymentStatus']);
        $inspection = $this->activityEvents('InspectGatewayCharge');
        self::assertContains(EventType::EVENT_TYPE_ACTIVITY_TASK_COMPLETED, $inspection, 'the Go worker answered InspectGatewayCharge');
        self::assertNotContains(EventType::EVENT_TYPE_ACTIVITY_TASK_TIMED_OUT, $inspection);
        self::assertSame($goQueue, $this->scheduledQueue('InspectGatewayCharge'), 'the activity went to the queue of the Go worker');
        self::assertSame(2, $this->countScheduled('ChargePayment'), 'one ChargePayment that gave up and one recovery');

        // T-M9-11
        $stats = $this->emulator->stats();
        self::assertSame(1, $stats['totals']['charges'], 'one charge at the gateway');
        self::assertSame(0, $stats['totals']['refunds'], 'no refund');
        self::assertCount(1, $stats['charges']);
        self::assertSame(1, $stats['charges'][$order['paymentId']]['executed'] ?? null, 'the charge of this payment was executed once');
    }

    /**
     * T-M9-12. Nobody polls the Go queue: `InspectGatewayCharge` waits ScheduleToStart (20 s), is not retried, and the order
     * ends as in M8 (`Failed(PaymentUnavailable)`). The branch costs `PrepareChargeInspection` plus ScheduleToStart.
     */
    #[Group('T-M9-12')]
    public function testWithoutGoWorkerTheOrderEndsAsBeforeM9(): void
    {
        $emptyQueue = $this->goQueue() . '-nobody-' . bin2hex(random_bytes(3));
        self::assertFalse($this->hasActivityPollers($emptyQueue), 'nobody polls ' . $emptyQueue);

        $order = $this->runSaga($emptyQueue, true, 150);

        self::assertSame(OrderStatus::Failed->value, $order['result'], $this->diagnostics());
        self::assertSame(OrderStatus::Failed->value, $order['orderStatus']);
        self::assertSame('PaymentUnavailable', $order['failureReason']);

        $scheduleToStart = $this->inspectionTimeouts();
        self::assertCount(1, $scheduleToStart, 'InspectGatewayCharge timed out once and was not retried: ' . $this->diagnostics());
        self::assertSame(TimeoutType::TIMEOUT_TYPE_SCHEDULE_TO_START, $scheduleToStart[0]['type']);
        self::assertSame(1, $this->countScheduled('InspectGatewayCharge'));

        // From the moment PrepareChargeInspection is scheduled to the moment the timeout is recorded.
        $branch = $scheduleToStart[0]['at'] - $this->firstScheduledAt('PrepareChargeInspection');
        self::assertGreaterThanOrEqual(self::INSPECTION_SCHEDULE_TO_START_SECONDS - 1.0, $branch, 'the whole ScheduleToStart was waited');
        self::assertLessThanOrEqual(self::INSPECTION_SCHEDULE_TO_START_SECONDS + 5.0, $branch, 'ScheduleToStart plus PrepareChargeInspection, no retries');
    }

    /**
     * T-M9-13. The Go worker is running, the flag is off (the default): the branch ends at `PrepareChargeInspection`, the
     * gateway is not asked, the order ends as in M8.
     */
    #[Group('T-M9-13')]
    public function testWithTheFlagOffTheGoWorkerIsNotCalled(): void
    {
        $goQueue = $this->goQueue();
        self::assertTrue($this->hasActivityPollers($goQueue), 'the Go worker polls ' . $goQueue . ' (start it with make test-go-e2e)');

        $order = $this->runSaga($goQueue, false, 150);

        self::assertSame(OrderStatus::Failed->value, $order['result'], $this->diagnostics());
        self::assertSame('PaymentUnavailable', $order['failureReason']);
        self::assertSame(0, $this->countScheduled('InspectGatewayCharge'), 'InspectGatewayCharge is not in the history');
        self::assertSame(1, $this->countScheduled('PrepareChargeInspection'), 'the flag is read by PrepareChargeInspection, which said "do not inspect"');
    }

    /**
     * Seeds a paid-for product, starts the PHP worker with the given Go queue and flag, runs the saga to its end.
     *
     * @param non-empty-string $goQueue
     *
     * @return array{result: string, orderStatus: string, failureReason: ?string, paymentStatus: ?string, paymentId: string}
     */
    private function runSaga(string $goQueue, bool $inspectionEnabled, int $timeoutSeconds): array
    {
        $product = $this->seedProduct();
        $this->seedKeys($product->id, 1);
        $order = $this->placeOrder(productId: ProductId::fromString($product->id->value));
        $this->infra->orders->save($order);
        $this->emulator->mode('late-success:' . self::CHARGE_PENDING_MILLISECONDS);

        $this->worker = new TemporalTestWorker();
        $this->worker->start($this->temporalAddress(), [
            'TEMPORAL_GO_TASK_QUEUE' => $goQueue,
            'TEMPORAL_GO_INSPECTION_ENABLED' => $inspectionEnabled ? '1' : '0',
            'TEMPORAL_E2E_CHARGE_SCHEDULE_TO_CLOSE' => (string) self::CHARGE_DEADLINE_SECONDS,
        ]);

        $configuration = self::getContainer()->get(SagaConfiguration::class);
        (new TemporalOrderSagaStarter(new TemporalClientFactory($this->temporalAddress()), $configuration, new NullLogger()))->start($order->id);
        $this->workflowId = SagaConfiguration::WORKFLOW_ID_PREFIX . $order->id->value;

        try {
            $result = $this->client()->newUntypedRunningWorkflowStub($this->workflowId)->getResult('string', $timeoutSeconds);
        } catch (\Throwable $e) {
            throw new \RuntimeException('The saga did not finish: ' . $e->getMessage() . "\n" . $this->diagnostics(), 0, $e);
        }

        $row = $this->connection->fetchAssociative('SELECT status, failure_reason FROM orders WHERE id = :id', ['id' => $order->id->value]);
        $payment = $this->connection->fetchAssociative('SELECT id, status FROM payments WHERE order_id = :id', ['id' => $order->id->value]);
        self::assertIsArray($row);
        self::assertIsArray($payment);

        return [
            'result' => is_string($result) ? $result : '',
            'orderStatus' => self::text($row['status']),
            'failureReason' => is_string($row['failure_reason']) ? $row['failure_reason'] : null,
            'paymentStatus' => self::text($payment['status']),
            'paymentId' => self::text($payment['id']),
        ];
    }

    /** @return non-empty-string */
    private function goQueue(): string
    {
        $queue = $_SERVER['TEMPORAL_GO_TASK_QUEUE'] ?? $_ENV['TEMPORAL_GO_TASK_QUEUE'] ?? '';

        if (!is_string($queue) || $queue === '') {
            self::markTestSkipped('TEMPORAL_GO_TASK_QUEUE is not set: run this group with make test-go-e2e, which starts the Go worker.');
        }

        return $queue;
    }

    /** @param non-empty-string $queue */
    private function hasActivityPollers(string $queue): bool
    {
        $response = $this->client()->getServiceClient()->DescribeTaskQueue(
            (new DescribeTaskQueueRequest())->setNamespace('default')->setTaskQueue((new TaskQueue())->setName($queue))->setTaskQueueType(TaskQueueType::TASK_QUEUE_TYPE_ACTIVITY),
        );

        return count($response->getPollers()) > 0;
    }

    /** @return list<HistoryEvent> */
    private function history(): array
    {
        $execution = $this->client()->newUntypedRunningWorkflowStub($this->workflowId ?? throw new \LogicException('The saga was not started.'))->describe()->info->execution;
        $events = [];

        foreach ($this->client()->getWorkflowHistory($execution)->getEvents() as $event) {
            $events[] = $event;
        }

        return $events;
    }

    /** @return array<int, string> scheduled event id => activity type */
    private function scheduledTypes(): array
    {
        $types = [];

        foreach ($this->history() as $event) {
            if ($event->getEventType() === EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED) {
                $types[(int) $event->getEventId()] = $event->getActivityTaskScheduledEventAttributes()?->getActivityType()?->getName() ?? '';
            }
        }

        return $types;
    }

    private function countScheduled(string $activityType): int
    {
        return count(array_filter($this->scheduledTypes(), static fn(string $type): bool => $type === $activityType));
    }

    private function scheduledQueue(string $activityType): string
    {
        foreach ($this->history() as $event) {
            $attributes = $event->getActivityTaskScheduledEventAttributes();

            if ($event->getEventType() === EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED && $attributes?->getActivityType()?->getName() === $activityType) {
                return $attributes->getTaskQueue()?->getName() ?? '';
            }
        }

        return '';
    }

    /** Seconds since the epoch of the first `ActivityTaskScheduled` of the type. */
    private function firstScheduledAt(string $activityType): float
    {
        foreach ($this->history() as $event) {
            if ($event->getEventType() === EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED && $event->getActivityTaskScheduledEventAttributes()?->getActivityType()?->getName() === $activityType) {
                return self::seconds($event);
            }
        }

        throw new \LogicException($activityType . ' was never scheduled.');
    }

    /**
     * Event types (`EventType::EVENT_TYPE_ACTIVITY_TASK_*`) recorded for the activity of the type.
     *
     * @return list<int>
     */
    private function activityEvents(string $activityType): array
    {
        $ids = array_keys(array_filter($this->scheduledTypes(), static fn(string $type): bool => $type === $activityType));
        $types = [];

        foreach ($this->history() as $event) {
            $scheduledId = match ($event->getEventType()) {
                EventType::EVENT_TYPE_ACTIVITY_TASK_COMPLETED => $event->getActivityTaskCompletedEventAttributes()?->getScheduledEventId(),
                EventType::EVENT_TYPE_ACTIVITY_TASK_FAILED => $event->getActivityTaskFailedEventAttributes()?->getScheduledEventId(),
                EventType::EVENT_TYPE_ACTIVITY_TASK_TIMED_OUT => $event->getActivityTaskTimedOutEventAttributes()?->getScheduledEventId(),
                default => null,
            };

            if ($scheduledId !== null && in_array((int) $scheduledId, $ids, true)) {
                $types[] = $event->getEventType();
            }
        }

        return $types;
    }

    /** @return list<array{type: int, at: float}> */
    private function inspectionTimeouts(): array
    {
        $ids = array_keys(array_filter($this->scheduledTypes(), static fn(string $type): bool => $type === 'InspectGatewayCharge'));
        $timeouts = [];

        foreach ($this->history() as $event) {
            $attributes = $event->getActivityTaskTimedOutEventAttributes();

            if ($event->getEventType() !== EventType::EVENT_TYPE_ACTIVITY_TASK_TIMED_OUT || $attributes === null || !in_array((int) $attributes->getScheduledEventId(), $ids, true)) {
                continue;
            }

            $timeouts[] = ['type' => $attributes->getFailure()?->getTimeoutFailureInfo()?->getTimeoutType() ?? TimeoutType::TIMEOUT_TYPE_UNSPECIFIED, 'at' => self::seconds($event)];
        }

        return $timeouts;
    }

    private static function seconds(HistoryEvent $event): float
    {
        $time = $event->getEventTime();

        return (float) ($time?->getSeconds() ?? 0) + ($time?->getNanos() ?? 0) / 1_000_000_000;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    /** Everything needed to explain a failure without a second run: the history with times, the worker log, the emulator state. */
    private function diagnostics(): string
    {
        $lines = [];

        try {
            $start = null;

            foreach ($this->history() as $event) {
                $start ??= self::seconds($event);
                $name = EventType::name($event->getEventType());
                $lines[] = sprintf('+%5.1fs #%d %s', self::seconds($event) - $start, (int) $event->getEventId(), is_string($name) ? $name : '?');
            }
        } catch (\Throwable $e) {
            $lines[] = 'history unavailable: ' . $e->getMessage();
        }

        try {
            $lines[] = 'gateway stats: ' . json_encode($this->emulator->stats());
        } catch (\Throwable) {
            $lines[] = 'gateway stats unavailable';
        }

        return implode("\n", $lines) . "\nworker output:\n" . ($this->worker?->output() ?? '');
    }

    private function client(): WorkflowClient
    {
        return (new TemporalClientFactory($this->temporalAddress()))->create();
    }

    /**
     * @return non-empty-string
     */
    private function temporalAddress(): string
    {
        $address = $_SERVER['TEMPORAL_ADDRESS'] ?? $_ENV['TEMPORAL_ADDRESS'] ?? 'temporal:7233';

        return is_string($address) && $address !== '' ? $address : 'temporal:7233';
    }
}
