<?php

declare(strict_types=1);

namespace App\Tests\Unit\Saga\Worker;

use App\Saga\Infrastructure\Temporal\Worker\ActivityLifecycle;
use App\Saga\Infrastructure\Temporal\Worker\WorkerConnectionGuard;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServicesResetter;
use Symfony\Contracts\Service\ResetInterface;

#[Group('T-I-43')]
#[Group('M6.4')]
final class ActivityLifecycleTest extends TestCase
{
    public function testResetsServicesAndDropsConnectionWithOpenTransaction(): void
    {
        $service = new class implements ResetInterface {
            public int $resets = 0;

            public function reset(): void
            {
                ++$this->resets;
            }
        };
        $connection = $this->createMock(Connection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::exactly(2))->method('close');

        $lifecycle = new ActivityLifecycle(
            new WorkerConnectionGuard($connection, new NullLogger()),
            new ServicesResetter(new \ArrayIterator(['stateful' => $service]), ['stateful' => ['reset']]),
        );
        $lifecycle->finish();
        $lifecycle->finish();

        self::assertSame(2, $service->resets, 'state is reset after every activity');
    }

    public function testServicesAreResetEvenWhenTheConnectionCannotBeSettled(): void
    {
        $service = new class implements ResetInterface {
            public int $resets = 0;

            public function reset(): void
            {
                ++$this->resets;
            }
        };
        $connection = self::createStub(Connection::class);
        $connection->method('isConnected')->willThrowException(new \RuntimeException('boom'));

        $lifecycle = new ActivityLifecycle(
            new WorkerConnectionGuard($connection, new NullLogger()),
            new ServicesResetter(new \ArrayIterator(['stateful' => $service]), ['stateful' => ['reset']]),
        );

        try {
            $lifecycle->finish();
            self::fail('The settle failure must surface.');
        } catch (\RuntimeException) {
            self::assertSame(1, $service->resets);
        }
    }
}
