<?php

declare(strict_types=1);

namespace App\Tests\Unit\Saga\Worker;

use App\Ordering\Domain\Exception\IllegalOrderTransition;
use App\Ordering\Domain\OrderStatus;
use App\Saga\Infrastructure\Temporal\Worker\TransientDatabaseFailure;
use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Connection problems are transient (retried), everything else keeps its own handling (EC-85).
 */
#[Group('T-I-43')]
#[Group('M6.4')]
final class TransientDatabaseFailureTest extends TestCase
{
    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function transient(): iterable
    {
        yield 'dbal connection lost' => [new ConnectionLost(self::driver('server closed the connection unexpectedly', '08006'), null)];
        yield 'dbal deadlock' => [new DeadlockException(self::driver('deadlock detected', '40P01'), null)];
        yield 'admin shutdown sqlstate' => [new DriverException(self::driver('terminating connection due to administrator command', '57P01'), null)];
        yield 'too many connections' => [new DriverException(self::driver('sorry, too many clients already', '53300'), null)];
        yield 'pdo without sqlstate' => [new \PDOException('SQLSTATE[HY000]: General error: 7 server closed the connection unexpectedly')];
        yield 'pdo with errorInfo' => [self::pdo('57P03')];
        yield 'wrapped as previous' => [new \RuntimeException('handler failed', 0, new ConnectionLost(self::driver('gone', '08006'), null))];
    }

    #[DataProvider('transient')]
    public function testConnectionProblemsAreTransient(\Throwable $failure): void
    {
        self::assertTrue(TransientDatabaseFailure::matches($failure));
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function permanent(): iterable
    {
        yield 'unique violation' => [new UniqueConstraintViolationException(self::driver('duplicate key', '23505'), null)];
        yield 'syntax error' => [new DriverException(self::driver('syntax error', '42601'), null)];
        yield 'business error' => [new IllegalOrderTransition(OrderStatus::Paid, OrderStatus::Pending)];
        yield 'plain runtime error' => [new \RuntimeException('connection refused by the gateway')];
        yield 'pdo constraint' => [self::pdo('23503')];
    }

    #[DataProvider('permanent')]
    public function testBusinessAndProgrammingErrorsAreNotTransient(\Throwable $failure): void
    {
        self::assertFalse(TransientDatabaseFailure::matches($failure));
    }

    private static function driver(string $message, string $sqlState): DriverExceptionInterface
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

    private static function pdo(string $sqlState): \PDOException
    {
        $failure = new \PDOException('driver error');
        $failure->errorInfo = [$sqlState, 7, 'driver error'];

        return $failure;
    }
}
