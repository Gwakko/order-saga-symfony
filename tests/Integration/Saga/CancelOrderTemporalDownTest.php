<?php

declare(strict_types=1);

namespace App\Tests\Integration\Saga;

use App\Ordering\Application\Command\CancelOrder\CancelOrderCommand;
use App\Ordering\Application\Command\CancelOrder\CancelOrderHandler;
use App\Ordering\Domain\Order;
use App\Saga\Infrastructure\Temporal\Config\SagaConfiguration;
use App\Saga\Infrastructure\Temporal\TemporalClientFactory;
use App\Saga\Infrastructure\Temporal\TemporalOrderSagaSignals;
use App\Saga\Infrastructure\Temporal\Workflow\OrderFulfillmentWorkflow;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Support\Fake\RecordingLogger;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Client\WorkflowClient;

/**
 * `CancelOrder` with the real `TemporalOrderSagaSignals` against the dev Temporal server of the compose stack. Workflows
 * go to the test task queue that nobody polls and are terminated at the end of each test.
 */
#[Group('M6.3')]
final class CancelOrderTemporalDownTest extends IntegrationTestCase
{
    /** @var list<non-empty-string> */
    private array $started = [];

    protected function tearDown(): void
    {
        foreach ($this->started as $workflowId) {
            try {
                $this->client($this->temporalAddress())->newUntypedRunningWorkflowStub($workflowId)->terminate('integration test cleanup');
            } catch (\Throwable) {
                // Already gone.
            }
        }

        parent::tearDown();
    }

    /** EC-44: Temporal is unreachable; the flag is saved, the answer is a success, the failure is logged. */
    #[Group('T-I-23')]
    public function testUnreachableTemporalKeepsTheFlagAndAnswersSuccessfully(): void
    {
        $order = $this->savedOrder();
        $logger = new RecordingLogger();

        $output = $this->handler('127.0.0.1:1', $logger)(new CancelOrderCommand($order->id->value, $order->customerId->value));

        self::assertTrue($output->cancellationRequested);
        self::assertTrue($this->infra->orders->get($order->id)->cancellationRequested, 'The flag is committed before any signal is tried.');
        self::assertTrue($logger->hasRecord('warning'), 'The undelivered signal is logged.');
        self::assertSame(1, $this->countRows('outbox_messages', "event_type = 'OrderCancellationRequested'"));
    }

    /** EC-38: the workflow does not exist yet; "not found" is not an error, the flag is read by its first guard. */
    #[Group('T-I-23')]
    public function testWorkflowThatIsNotStartedYetIsNotAnError(): void
    {
        $order = $this->savedOrder();
        $logger = new RecordingLogger();

        $output = $this->handler($this->temporalAddress(), $logger)(new CancelOrderCommand($order->id->value, $order->customerId->value));

        self::assertTrue($output->cancellationRequested);
        self::assertFalse($logger->hasRecord('warning'), 'Nothing to warn about: the signal had no recipient and needs none.');
        self::assertTrue($this->infra->orders->get($order->id)->cancellationRequested);
    }

    /** A running saga receives the `requestCancellation` signal. */
    #[Group('T-I-23')]
    public function testRunningWorkflowReceivesTheSignal(): void
    {
        $order = $this->savedOrder();
        $client = $this->client($this->temporalAddress());
        $configuration = $this->configuration();
        $stub = $client->newUntypedWorkflowStub(OrderFulfillmentWorkflow::TYPE, $configuration->workflowOptions($order->id->value));
        $run = $client->start($stub, $order->id->value);
        $this->started[] = $configuration::WORKFLOW_ID_PREFIX . $order->id->value;

        $this->handler($this->temporalAddress(), new RecordingLogger())(new CancelOrderCommand($order->id->value, $order->customerId->value));

        $signals = [];
        foreach ($client->getWorkflowHistory($run->getExecution())->getEvents() as $event) {
            if ($event->getEventType() === EventType::EVENT_TYPE_WORKFLOW_EXECUTION_SIGNALED) {
                $signals[] = $event->getWorkflowExecutionSignaledEventAttributes()?->getSignalName();
            }
        }

        self::assertSame(['requestCancellation'], $signals);
    }

    private function savedOrder(): Order
    {
        $order = $this->placeOrder();
        $this->infra->orders->save($order);

        return $order;
    }

    /**
     * @param non-empty-string $temporalAddress
     */
    private function handler(string $temporalAddress, RecordingLogger $logger): CancelOrderHandler
    {
        $signals = new TemporalOrderSagaSignals(new TemporalClientFactory($temporalAddress), $logger);

        return new CancelOrderHandler($this->infra->orders, $signals, $this->infra->transactions, new MockClock('2026-05-01T10:01:00Z'), $logger);
    }

    private function configuration(): SagaConfiguration
    {
        return self::getContainer()->get(SagaConfiguration::class);
    }

    /**
     * @param non-empty-string $address
     */
    private function client(string $address): WorkflowClient
    {
        return (new TemporalClientFactory($address))->create();
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
