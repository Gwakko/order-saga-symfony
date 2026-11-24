<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messaging;

use App\Shared\Infrastructure\Outbox\MessengerOutboxPublisher;
use App\Shared\Infrastructure\Outbox\OutboxMessage;
use App\Shared\Infrastructure\Outbox\OutboxRelay;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\Support\Messenger\PoisonAwareOutboxHandler;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Real Redis Streams. The consumer is `PoisonAwareOutboxHandler` (tests/Integration/Support/Messenger).
 */
#[Group('M4.3')]
final class MessengerFailureTransportTest extends IntegrationTestCase
{
    private const string STREAM = 'domain_events_test';
    private const string FAILED_STREAM = 'domain_events_failed_test';

    protected function setUp(): void
    {
        parent::setUp();

        $redis = $this->redis();
        $redis->del(self::STREAM, self::FAILED_STREAM);
        PoisonAwareOutboxHandler::$handled = [];
        PoisonAwareOutboxHandler::$poisonAttempts = 0;
    }

    private function redis(): \Redis
    {
        $redis = new \Redis();
        $redis->connect('redis', 6379);

        return $redis;
    }

    private function message(string $id, string $eventType = 'OrderPlaced'): OutboxMessage
    {
        return new OutboxMessage($id, 'test', self::uuid(), 1, $eventType, ['k' => 'v'], '2026-05-01 10:00:00.000000+00:00');
    }

    private function consume(int $limit): string
    {
        $application = new Application(self::$kernel ?? throw new \LogicException('no kernel'));
        $application->setAutoExit(false);
        $output = new BufferedOutput();
        $application->run(new ArrayInput([
            'command' => 'messenger:consume',
            'receivers' => ['domain_events'],
            '--limit' => (string) $limit,
            '--time-limit' => '30',
            '--sleep' => '0.05',
            '--no-interaction' => true,
        ]), $output);

        return $output->fetch();
    }

    #[Group('T-I-36')]
    public function testPoisonMessageEndsOnTheFailureTransportAndOthersAreHandled(): void
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $good1 = self::uuid();
        $good2 = self::uuid();
        $bus->dispatch($this->message($good1));
        $bus->dispatch($this->message(self::uuid(), 'Poison'));
        $bus->dispatch($this->message($good2));

        // 2 good deliveries + the poison one: first attempt and 5 retries (config: max_retries 5).
        $this->consume(8);

        self::assertSame([$good1, $good2], PoisonAwareOutboxHandler::$handled);
        self::assertSame(6, PoisonAwareOutboxHandler::$poisonAttempts);
        self::assertSame(1, $this->redis()->xlen(self::FAILED_STREAM), 'the poison message waits on the failure transport');

        $failed = $this->redis()->xrange(self::FAILED_STREAM, '-', '+');
        self::assertIsArray($failed);
        $entry = array_values($failed)[0];
        self::assertIsArray($entry);
        self::assertIsString($entry['message']);
        self::assertJson($entry['message'], 'the failed message keeps the JSON format');
        self::assertStringNotContainsString('O:', $entry['message'], 'no PHP serialize() payload');
    }

    public function testRelayPublishesToTheRealTransportAndTheConsumerReceivesIt(): void
    {
        $order = $this->placeOrder();
        $this->infra->orders->save($order);

        $relay = new OutboxRelay(
            $this->connection,
            $this->infra->transactions,
            new MessengerOutboxPublisher(self::getContainer()->get(MessageBusInterface::class)),
            new MockClock('2026-05-01T12:00:00Z'),
        );
        self::assertSame(1, $relay->publishBatch());
        self::assertSame(1, $this->redis()->xlen(self::STREAM));

        $this->consume(1);

        self::assertSame([$order->pendingEvents[0]->eventId()->value], PoisonAwareOutboxHandler::$handled);
        self::assertSame(0, $this->redis()->xlen(self::FAILED_STREAM));
    }
}
