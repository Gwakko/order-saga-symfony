<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Payment\Infrastructure\Gateway\HttpPaymentGateway;
use App\Saga\Infrastructure\Temporal\Config\SagaConfiguration;
use App\Saga\Infrastructure\Temporal\Consumer\StartOrderRefundOnOrderRefundStarted;
use App\Saga\Infrastructure\Temporal\TemporalClientFactory;
use App\Saga\Infrastructure\Temporal\TemporalOrderRefundStarter;
use App\Shared\Infrastructure\Inbox\InboxGuard;
use App\Shared\Infrastructure\Outbox\DomainEventCodec;
use App\Shared\Infrastructure\Outbox\OutboxMessage;
use App\Shared\Infrastructure\Persistence\DbTimestamp;
use App\Shared\Infrastructure\Persistence\Row;
use App\Tests\Integration\Support\GatewayEmulator;
use App\Tests\Integration\Support\Infra;
use App\Tests\Integration\Support\RefundFixture;
use App\Tests\Integration\Support\TemporalTestWorker;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\HttpClient;

/**
 * EC-61 from the HTTP side to the money: two refund requests, the outbox event delivered more than once, one
 * `RefundWorkflow` run by the real worker, one refund executed at the gateway emulator, the order `refunded`.
 */
#[Group('M6.3')]
final class RefundEndToEndTest extends FunctionalTestCase
{
    private GatewayEmulator $emulator;
    private TemporalTestWorker $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->emulator = GatewayEmulator::fromEnvironment();
        $this->emulator->reset();
        $this->worker = new TemporalTestWorker();
    }

    protected function tearDown(): void
    {
        $this->worker->stop();
        $this->emulator->reset();
        parent::tearDown();
    }

    #[Group('T-F-12')]
    public function testDoubleRefundRequestRefundsOnce(): void
    {
        $gateway = new HttpPaymentGateway(HttpClient::create(), $this->emulator->baseUri, 2.0);
        [$order, $payment] = (new RefundFixture(new Infra($this->connection), $this->connection, $gateway))->create(false);
        $customer = $order->customerId->value;
        $uri = sprintf('/api/orders/%s/refund', $order->id->value);

        $first = $this->request('POST', $uri, $customer);
        $second = $this->request('POST', $uri, $customer);

        self::assertSame(202, $first->getStatusCode(), (string) $first->getContent());
        self::assertSame(202, $second->getStatusCode());
        self::assertSame(self::json($first), self::json($second), 'the second request answers with the current status');
        self::assertSame(1, $this->countRows('outbox_messages', "event_type = 'OrderRefundStarted'"));

        $this->worker->start($this->temporalAddress());
        $message = $this->refundStartedMessage();
        $consumer = $this->consumer();
        $consumer($message);
        $consumer($message); // redelivery: the inbox drops it
        $consumer(new OutboxMessage(self::uuid(), $message->aggregateType, $message->aggregateId, $message->aggregateVersion, $message->eventType, $message->payload, $message->occurredAt)); // another message, same order: the starter absorbs it

        $client = (new TemporalClientFactory($this->temporalAddress()))->create();
        $workflowId = SagaConfiguration::REFUND_WORKFLOW_ID_PREFIX . $order->id->value;
        $result = $client->newUntypedRunningWorkflowStub($workflowId)->getResult('string', 60);

        self::assertSame('Refunded', $result);
        $refund = $this->emulator->stats()['refunds']['refund-' . $payment->id->value];
        self::assertSame(1, $refund['executed'], 'one refund at the gateway');
        self::assertSame(1, $refund['requests']);
        self::assertSame(1, $this->countRows('payments', "status = 'refunded'"));

        $this->projectOutbox();
        $view = self::json($this->request('GET', sprintf('/api/orders/%s', $order->id->value), $customer));
        self::assertSame('refunded', $view['status']);
        self::assertNull($view['keyCode'], 'a refunded order does not show its key (INV-17)');
    }

    private function consumer(): StartOrderRefundOnOrderRefundStarted
    {
        $container = self::getContainer();
        $starter = new TemporalOrderRefundStarter(new TemporalClientFactory($this->temporalAddress()), $container->get(SagaConfiguration::class), new NullLogger());

        return new StartOrderRefundOnOrderRefundStarted($container->get(InboxGuard::class), $container->get(DomainEventCodec::class), $starter);
    }

    private function refundStartedMessage(): OutboxMessage
    {
        $raw = $this->connection->fetchAssociative(
            "SELECT id, aggregate_type, aggregate_id, aggregate_version, event_type, payload::text AS payload, occurred_at
             FROM outbox_messages WHERE event_type = 'OrderRefundStarted'",
        );
        $row = new Row($raw === false ? throw new \LogicException('OrderRefundStarted was not written to the outbox.') : $raw);
        /** @var array<string, mixed> $payload */
        $payload = json_decode($row->string('payload'), true, 512, JSON_THROW_ON_ERROR);

        return new OutboxMessage(
            $row->string('id'),
            $row->string('aggregate_type'),
            $row->string('aggregate_id'),
            $row->int('aggregate_version'),
            $row->string('event_type'),
            $payload,
            DbTimestamp::format($row->time('occurred_at')),
        );
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
