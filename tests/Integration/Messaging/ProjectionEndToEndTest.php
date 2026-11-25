<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messaging;

use App\Ordering\Domain\OrderStatus;
use App\Ordering\Infrastructure\Persistence\DbalOrderReadModel;
use App\Shared\Domain\Identifier\ReservationId;
use App\Shared\Infrastructure\Outbox\MessengerOutboxPublisher;
use App\Shared\Infrastructure\Outbox\OutboxRelay;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\Support\TemporalTestQueue;
use App\Tests\Integration\Support\Messenger\PoisonAwareOutboxHandler;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The whole path: repository -> outbox -> relay -> Redis Stream (JSON) -> `messenger:consume` -> `order_view`.
 */
#[Group('M4.6')]
final class ProjectionEndToEndTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        // The consumer handles OrderPlaced like production does and starts the order saga on the test task queue.
        try {
            TemporalTestQueue::terminateRunningOnConfiguredServer();
        } catch (\Throwable) {
            // Temporal is not needed to pass these tests.
        }

        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $redis = new \Redis();
        $redis->connect('redis', 6379);
        $redis->del('domain_events_test', 'domain_events_failed_test');
        PoisonAwareOutboxHandler::$handled = [];
        PoisonAwareOutboxHandler::$poisonAttempts = 0;
    }

    public function testOrderEventsReachTheReadModelThroughRedis(): void
    {
        $order = $this->placeOrder()->markKeyReserved(ReservationId::fromString(self::uuid()), $this->t0->modify('+1 second'));
        $this->infra->orders->save($order);

        $relay = new OutboxRelay(
            $this->connection,
            $this->infra->transactions,
            new MessengerOutboxPublisher(self::getContainer()->get(MessageBusInterface::class)),
            new MockClock('2026-05-01T12:00:00Z'),
        );
        self::assertSame(2, $relay->publishBatch());

        $application = new Application(self::$kernel ?? throw new \LogicException('no kernel'));
        $application->setAutoExit(false);
        $exit = $application->run(new ArrayInput([
            'command' => 'messenger:consume',
            'receivers' => ['domain_events'],
            '--limit' => '2',
            '--time-limit' => '30',
            '--sleep' => '0.05',
            '--no-interaction' => true,
        ]), new BufferedOutput());
        self::assertSame(0, $exit);

        $readModel = new DbalOrderReadModel($this->connection);
        $view = $readModel->find($order->id, $order->customerId);

        self::assertNotNull($view);
        self::assertSame(OrderStatus::KeyReserved, $view->status);
        self::assertSame($order->price->amount, $view->price->amount);
        self::assertCount(2, PoisonAwareOutboxHandler::$handled);
        self::assertSame(2, $this->countRows('inbox_messages', "consumer = 'order_projection'"));
    }
}
