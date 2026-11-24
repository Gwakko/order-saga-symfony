<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messaging;

use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

#[Group('M4.3')]
final class OutboxRelayCommandTest extends IntegrationTestCase
{
    public function testOnceDrainsTheOutboxAndExits(): void
    {
        $redis = new \Redis();
        $redis->connect('redis', 6379);
        $redis->del('domain_events_test');

        $ids = $this->seedOutbox(5);

        $application = new Application(self::$kernel ?? throw new \LogicException('no kernel'));
        $tester = new CommandTester($application->find('app:outbox:relay'));
        $exit = $tester->execute(['--once' => true, '--batch' => '2']);

        self::assertSame(0, $exit);
        self::assertSame(0, $this->countRows('outbox_messages', 'published_at IS NULL'));
        self::assertSame(5, $redis->xlen('domain_events_test'));

        // The transport body is JSON, not PHP serialize().
        $entries = $redis->xrange('domain_events_test', '-', '+');
        self::assertIsArray($entries);
        $first = array_values($entries)[0];
        self::assertIsArray($first);
        self::assertIsString($first['message']);
        $envelope = json_decode($first['message'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($envelope);
        self::assertIsString($envelope['body']);
        $body = json_decode($envelope['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame($ids[0], $body['messageId']);
        self::assertSame('OrderPlaced', $body['eventType']);
    }
}
