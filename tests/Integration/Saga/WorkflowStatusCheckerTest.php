<?php

declare(strict_types=1);

namespace App\Tests\Integration\Saga;

use App\Ordering\Application\Exception\WorkflowStatusUnavailable;
use App\Ordering\Application\Port\SagaWorkflowKind;
use App\Saga\Infrastructure\Temporal\Config\SagaConfiguration;
use App\Saga\Infrastructure\Temporal\TemporalClientFactory;
use App\Saga\Infrastructure\Temporal\TemporalOrderSagaStarter;
use App\Saga\Infrastructure\Temporal\TemporalWorkflowStatusChecker;
use App\Shared\Domain\Identifier\OrderId;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Temporal\Client\WorkflowClient;

/**
 * `TemporalWorkflowStatusChecker` against the dev Temporal server of the compose stack. Workflows are started on the
 * test task queue (no worker polls it) and terminated at the end of each test.
 */
#[Group('M8a')]
final class WorkflowStatusCheckerTest extends IntegrationTestCase
{
    /** @var list<non-empty-string> */
    private array $started = [];

    protected function tearDown(): void
    {
        foreach ($this->started as $workflowId) {
            try {
                $this->client()->newUntypedRunningWorkflowStub($workflowId)->terminate('integration test cleanup');
            } catch (\Throwable) {
                // Already gone.
            }
        }

        parent::tearDown();
    }

    #[Group('T-M8A-28')]
    public function testRunningTerminatedAndUnknownWorkflows(): void
    {
        $checker = new TemporalWorkflowStatusChecker(new TemporalClientFactory($this->temporalAddress()));
        $order = OrderId::fromString(self::uuid());

        self::assertFalse($checker->isRunning($order, SagaWorkflowKind::Fulfillment), 'never started: not running');

        $this->start($order);
        self::assertTrue($checker->isRunning($order, SagaWorkflowKind::Fulfillment));
        self::assertFalse($checker->isRunning($order, SagaWorkflowKind::Refund), 'the refund workflow is another id');

        $this->client()->newUntypedRunningWorkflowStub('order-' . $order->value)->terminate('test');
        self::assertFalse($checker->isRunning($order, SagaWorkflowKind::Fulfillment), 'a terminated workflow is not running');
    }

    #[Group('T-M8A-29')]
    public function testUnreachableServerIsUnknownNotDead(): void
    {
        $checker = new TemporalWorkflowStatusChecker(new TemporalClientFactory('127.0.0.1:1', callTimeoutSeconds: 1, maxAttempts: 1));

        $this->expectException(WorkflowStatusUnavailable::class);
        $checker->isRunning(OrderId::fromString(self::uuid()), SagaWorkflowKind::Fulfillment);
    }

    private function start(OrderId $order): void
    {
        $configuration = self::getContainer()->get(SagaConfiguration::class);
        (new TemporalOrderSagaStarter(new TemporalClientFactory($this->temporalAddress()), $configuration, new NullLogger()))->start($order);
        $this->started[] = 'order-' . $order->value;
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
