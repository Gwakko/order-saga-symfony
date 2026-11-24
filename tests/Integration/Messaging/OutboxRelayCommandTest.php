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

        $this->seedOutbox(5);

        $application = new Application(self::$kernel ?? throw new \LogicException('no kernel'));
        $tester = new CommandTester($application->find('app:outbox:relay'));
        $exit = $tester->execute(['--once' => true, '--batch' => '2']);

        self::assertSame(0, $exit);
        self::assertSame(0, $this->countRows('outbox_messages', 'published_at IS NULL'));
        self::assertSame(5, $redis->xlen('domain_events_test'));
    }
}
