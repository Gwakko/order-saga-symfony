<?php

declare(strict_types=1);

namespace App\Tests\Unit\Saga\Worker;

use App\Saga\Infrastructure\Temporal\Worker\WorkerConnectionGuard;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\SyntaxErrorException;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[Group('T-I-43')]
#[Group('M6.4')]
final class WorkerConnectionGuardTest extends TestCase
{
    public function testHealthyConnectionIsKept(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->expects(self::once())->method('executeQuery')->with('SELECT 1')->willReturn(self::createStub(Result::class));
        $connection->expects(self::never())->method('close');

        (new WorkerConnectionGuard($connection, new NullLogger()))->ensureAlive();
    }

    public function testConnectionNotOpenedYetIsNotProbed(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isConnected')->willReturn(false);
        $connection->expects(self::never())->method('executeQuery');
        $connection->expects(self::never())->method('close');

        (new WorkerConnectionGuard($connection, new NullLogger()))->ensureAlive();
    }

    public function testLostConnectionIsClosedSoTheNextQueryReconnects(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('executeQuery')->willThrowException(new ConnectionLost($this->driver('server closed the connection unexpectedly', '08006'), null));
        $connection->expects(self::once())->method('close');

        (new WorkerConnectionGuard($connection, new NullLogger()))->ensureAlive();
    }

    public function testNonConnectionErrorOfTheProbeIsNotSwallowed(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('executeQuery')->willThrowException(new SyntaxErrorException($this->driver('syntax', '42601'), null));
        $connection->expects(self::never())->method('close');

        $this->expectException(SyntaxErrorException::class);
        (new WorkerConnectionGuard($connection, new NullLogger()))->ensureAlive();
    }

    public function testOpenTransactionAfterActivityDropsTheConnection(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::once())->method('close');

        (new WorkerConnectionGuard($connection, new NullLogger()))->settle();
    }

    public function testCleanConnectionAfterActivityIsKept(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->expects(self::never())->method('close');

        (new WorkerConnectionGuard($connection, new NullLogger()))->settle();
    }

    private function driver(string $message, string $sqlState): DriverExceptionInterface
    {
        return new class ($message, $sqlState) extends \Exception implements DriverExceptionInterface {
            public function __construct(string $message, private readonly string $sqlState)
            {
                parent::__construct($message);
            }

            public function getSQLState(): string
            {
                return $this->sqlState;
            }
        };
    }
}
