<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messaging;

use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Domain\Identifier\ReservationId;
use App\Saga\Infrastructure\Temporal\Config\SagaConfiguration;
use App\Saga\Infrastructure\Temporal\Consumer\StartOrderSagaOnOrderPlaced;
use App\Saga\Infrastructure\Temporal\TemporalClientFactory;
use App\Saga\Infrastructure\Temporal\TemporalOrderSagaStarter;
use App\Shared\Infrastructure\Inbox\InboxGuard;
use App\Shared\Infrastructure\Outbox\DomainEventCodec;
use App\Shared\Infrastructure\Outbox\OutboxMessage;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Temporal\Client\WorkflowClient;
use Temporal\Exception\Client\ServiceClientException;

/**
 * `OrderPlaced` from the outbox starts the saga on the dev Temporal server of the compose stack (only `temporal` is
 * needed: the workflows are started on the test task queue `order-saga-test`, which no worker polls, and are terminated
 * at the end of each test).
 */
#[Group('M6.2')]
final class StartOrderSagaConsumerTest extends IntegrationTestCase
{
    /** @var list<non-empty-string> */
    private array $started = [];

    protected function tearDown(): void
    {
        foreach ($this->started as $workflowId) {
            try {
                $this->client()->newUntypedRunningWorkflowStub($workflowId)->terminate('integration test cleanup');
            } catch (\Throwable) {
                // Already gone: nothing to clean.
            }
        }

        parent::tearDown();
    }

    /** EC-11: the same message twice, and the same order in two different messages, give one workflow and no error. */
    #[Group('T-I-20')]
    public function testDuplicateStartIsSuccess(): void
    {
        $order = $this->placeOrder();
        $message = $this->messageFor($order->pendingEvents[0] ?? throw new \LogicException('OrderPlaced expected'));
        $consumer = $this->consumer($this->temporalAddress());
        $this->started[] = 'order-' . $order->id->value;

        $consumer($message);
        $consumer($message); // the inbox drops the redelivery
        $consumer(new OutboxMessage(self::uuid(), $message->aggregateType, $message->aggregateId, $message->aggregateVersion, $message->eventType, $message->payload, $message->occurredAt)); // another message, same order: the starter absorbs it

        $description = $this->client()->newUntypedRunningWorkflowStub('order-' . $order->id->value)->describe();
        self::assertSame('OrderFulfillment', $description->info->type->name);
        self::assertSame(2, $this->countRows('inbox_messages', "consumer = 'start_order_saga'"), 'Two distinct message ids were applied; the redelivery was dropped.');
    }

    /** EC-58: Temporal is down, the consumer fails, no inbox row stays, and the redelivery after recovery starts the saga once. */
    #[Group('T-I-20')]
    public function testTemporalUnavailableFailsTheConsumerSoMessengerRetries(): void
    {
        $order = $this->placeOrder();
        $message = $this->messageFor($order->pendingEvents[0] ?? throw new \LogicException('OrderPlaced expected'));
        $this->started[] = 'order-' . $order->id->value;

        try {
            ($this->consumer('127.0.0.1:1'))($message);
            self::fail('A start against an unreachable Temporal must fail, so that Messenger retries.');
        } catch (ServiceClientException) {
            // Expected.
        }

        self::assertSame(0, $this->countRows('inbox_messages', "consumer = 'start_order_saga'"), 'A failed start leaves no inbox row, so the retry runs.');

        ($this->consumer($this->temporalAddress()))($message); // Temporal is back

        self::assertSame(1, $this->countRows('inbox_messages', "consumer = 'start_order_saga'"));
        self::assertSame('OrderFulfillment', $this->client()->newUntypedRunningWorkflowStub('order-' . $order->id->value)->describe()->info->type->name);
    }

    public function testOtherEventsDoNotStartTheSaga(): void
    {
        $order = $this->placeOrder()->markKeyReserved(ReservationId::fromString(self::uuid()), $this->t0->modify('+1 second'));
        $reserved = $this->messageFor($order->pendingEvents[1] ?? throw new \LogicException('OrderKeyReserved expected'));

        ($this->consumer('127.0.0.1:1'))($reserved); // would throw if it tried to reach Temporal

        self::assertSame(0, $this->countRows('inbox_messages', "consumer = 'start_order_saga'"));
    }

    /**
     * @param non-empty-string $temporalAddress
     */
    private function consumer(string $temporalAddress): StartOrderSagaOnOrderPlaced
    {
        $container = self::getContainer();
        $configuration = $container->get(SagaConfiguration::class);
        $starter = new TemporalOrderSagaStarter(new TemporalClientFactory($temporalAddress), $configuration, new NullLogger());

        return new StartOrderSagaOnOrderPlaced($container->get(InboxGuard::class), $container->get(DomainEventCodec::class), $starter);
    }

    private function messageFor(DomainEvent $event): OutboxMessage
    {
        return self::getContainer()->get(DomainEventCodec::class)->toMessage($event);
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
