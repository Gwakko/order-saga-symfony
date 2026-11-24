<?php

declare(strict_types=1);

namespace App\Tests\Integration\Worker;

use App\Ordering\Domain\OrderStatus;
use App\Saga\Infrastructure\Temporal\Activity\OrderActivitiesImpl;
use App\Saga\Infrastructure\Temporal\Config\SagaConfiguration;
use App\Saga\Infrastructure\Temporal\TemporalClientFactory;
use App\Saga\Infrastructure\Temporal\TemporalOrderSagaStarter;
use App\Saga\Infrastructure\Temporal\Worker\ActivityResilienceInterceptor;
use App\Saga\Infrastructure\Temporal\Worker\WorkerConnectionGuard;
use App\Saga\Infrastructure\Temporal\Workflow\OrderFulfillmentWorkflow;
use App\Shared\Domain\Identifier\ProductId;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\Support\GatewayEmulator;
use App\Tests\Integration\Support\TemporalTestWorker;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Temporal\Api\Enums\V1\EventType;
use Temporal\DataConverter\EncodedValues;
use Temporal\Interceptor\ActivityInbound\ActivityInput;
use Temporal\Interceptor\Header;

/**
 * EC-85: a long-lived worker must survive the loss of its database connection. A Postgres restart closes every client
 * socket, which `pg_terminate_backend` reproduces from inside the php container (it has no docker CLI); the real
 * `docker compose restart postgres` is done by `bin/worker-postgres-restart-check` from the host (see docs/reports/M6.4.md).
 */
#[Group('M6.4')]
#[Group('T-I-43')]
final class WorkerDbReconnectTest extends IntegrationTestCase
{
    private ?TemporalTestWorker $worker = null;
    private ?GatewayEmulator $emulator = null;
    /** @var non-empty-string|null */
    private ?string $workflowId = null;

    protected function tearDown(): void
    {
        $this->worker?->stop();

        if ($this->workflowId !== null) {
            try {
                (new TemporalClientFactory($this->temporalAddress()))->create()->newUntypedRunningWorkflowStub($this->workflowId)->terminate('integration test cleanup');
            } catch (\Throwable) {
                // Already finished.
            }
        }

        $this->emulator?->reset();
        parent::tearDown();
    }

    /**
     * The activity that follows a dropped connection runs on a fresh one, with no failure and no retry: the interceptor
     * probes the connection first (what happens between two activities after a Postgres restart).
     */
    public function testNextActivityReconnectsAfterTheConnectionWasDropped(): void
    {
        $order = $this->placeOrder();
        $this->infra->orders->save($order);
        $guard = new WorkerConnectionGuard($this->connection, new NullLogger());
        $interceptor = new ActivityResilienceInterceptor($guard);
        $activities = new OrderActivitiesImpl(self::getContainer()->get(\App\Shared\Infrastructure\Bus\CommandBus::class), $this->infra->orders);

        self::assertFalse($activities->isCancellationRequested($order->id->value), 'the connection works before the drop');
        self::assertGreaterThan(0, $this->terminateOtherSessions(), 'the worker connection was open and is now killed');

        $result = $interceptor->handleActivityInbound(
            new ActivityInput(EncodedValues::empty(), Header::empty()),
            fn(ActivityInput $input): bool => $activities->isCancellationRequested($order->id->value),
        );

        self::assertFalse($result);
    }

    /**
     * The same drop in the middle of an activity: the first attempt fails with a retryable error and the connection is
     * discarded, so the retry (Temporal's, here called again by hand) succeeds on a new connection.
     */
    public function testDropInsideAnActivityIsRetryableAndTheRetryReconnects(): void
    {
        $order = $this->placeOrder();
        $this->infra->orders->save($order);
        $guard = new WorkerConnectionGuard($this->connection, new NullLogger());
        $interceptor = new ActivityResilienceInterceptor($guard);
        $activities = new OrderActivitiesImpl(self::getContainer()->get(\App\Shared\Infrastructure\Bus\CommandBus::class), $this->infra->orders);
        $input = new ActivityInput(EncodedValues::empty(), Header::empty());

        $this->connection->executeQuery('SELECT 1')->free();

        try {
            $interceptor->handleActivityInbound($input, function (ActivityInput $in) use ($activities, $order): bool {
                $this->terminateOtherSessions(); // the connection dies while the activity runs
                $this->connection->executeQuery('SELECT 1')->free(); // the first query after the drop (no probe in front of it)

                return $activities->isCancellationRequested($order->id->value);
            });
            self::fail('The first attempt must fail.');
        } catch (\Temporal\Exception\Failure\ApplicationFailure $failure) {
            self::assertSame(ActivityResilienceInterceptor::FAILURE_TYPE, $failure->getType());
            self::assertFalse($failure->isNonRetryable());
        }

        self::assertFalse($this->connection->isConnected(), 'the dead connection was closed');
        self::assertFalse($interceptor->handleActivityInbound($input, fn(ActivityInput $in): bool => $activities->isCancellationRequested($order->id->value)));
    }

    /**
     * Whole saga with the real worker: all database sessions are killed while the gateway is still answering the
     * charge (the emulator holds the answer for 4 s). The charge activity fails once on the dead connection, is retried
     * and the order still ends `Completed`, with the charge executed once at the gateway.
     */
    #[Group('slow')]
    public function testOrderCompletesAfterTheDatabaseConnectionsAreKilledMidSaga(): void
    {
        $product = $this->seedProduct();
        $this->seedKeys($product->id, 1);
        $order = $this->placeOrder(productId: ProductId::fromString($product->id->value));
        $this->infra->orders->save($order);
        $this->emulator = GatewayEmulator::fromEnvironment();
        $this->emulator->reset();
        $this->emulator->mode('delay:4000');

        $this->worker = new TemporalTestWorker();
        $this->worker->start($this->temporalAddress());

        $configuration = self::getContainer()->get(SagaConfiguration::class);
        (new TemporalOrderSagaStarter(new TemporalClientFactory($this->temporalAddress()), $configuration, new NullLogger()))->start($order->id);
        $this->workflowId = SagaConfiguration::WORKFLOW_ID_PREFIX . $order->id->value;

        $killed = 0;

        for ($attempt = 0; $attempt < 40 && $killed === 0; ++$attempt) {
            usleep(250_000);
            // The payment row exists: the charge activity is waiting for the (delayed) answer of the gateway.
            if ($this->tryCount('payments') > 0) {
                $killed = $this->terminateOtherSessions();
            }
        }

        self::assertGreaterThan(0, $killed, 'the saga reached the charge and its database sessions were killed');

        $result = (new TemporalClientFactory($this->temporalAddress()))->create()->newUntypedRunningWorkflowStub($this->workflowId)->getResult('string', 90);

        $this->connection->close(); // the test's own connection was killed too
        self::assertSame('Completed', $result);
        self::assertSame(OrderStatus::Completed, $this->infra->orders->get($order->id)->status);
        self::assertSame(1, $this->emulator->stats()['totals']['charges'], 'the gateway charged once');
        self::assertGreaterThanOrEqual(2, $this->highestActivityAttempt(), 'the activity that lost its connection was retried');
    }

    /** The highest attempt number of any activity start in the workflow history. */
    private function highestActivityAttempt(): int
    {
        $client = (new TemporalClientFactory($this->temporalAddress()))->create();
        $execution = $client->newUntypedRunningWorkflowStub($this->workflowId ?? throw new \LogicException('The saga was not started.'))->describe()->info->execution;
        $highest = 0;

        foreach ($client->getWorkflowHistory($execution)->getEvents() as $event) {
            if ($event->getEventType() === EventType::EVENT_TYPE_ACTIVITY_TASK_STARTED) {
                $highest = max($highest, $event->getActivityTaskStartedEventAttributes()?->getAttempt() ?? 0);
            }
        }

        return $highest;
    }

    private function tryCount(string $table): int
    {
        try {
            return $this->countRows($table);
        } catch (\Throwable) {
            $this->connection->close();

            return 0;
        }
    }

    /**
     * What a Postgres restart does to its clients: every session of the test database except the admin one is closed.
     */
    private function terminateOtherSessions(): int
    {
        $params = $this->connection->getParams();
        $name = $params['dbname'] ?? throw new \LogicException('The test connection has no database name.');
        $admin = DriverManager::getConnection([...$params, 'dbname' => 'postgres']);

        try {
            $killed = $admin->fetchFirstColumn('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = :name AND pid <> pg_backend_pid()', ['name' => $name]);
        } finally {
            $admin->close();
        }

        return count($killed);
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
