<?php

declare(strict_types=1);

namespace App\Tests\Unit\Saga\Worker;

use App\Ordering\Domain\Exception\IllegalOrderTransition;
use App\Ordering\Domain\OrderStatus;
use App\Saga\Infrastructure\Temporal\Config\SagaConfiguration;
use App\Saga\Infrastructure\Temporal\Worker\ActivityResilienceInterceptor;
use App\Saga\Infrastructure\Temporal\Worker\WorkerConnectionGuard;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\ConnectionLost;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Yaml\Yaml;
use Temporal\DataConverter\EncodedValues;
use Temporal\Exception\Failure\ApplicationFailure;
use Temporal\Interceptor\ActivityInbound\ActivityInput;
use Temporal\Interceptor\Header;

#[Group('T-I-43')]
#[Group('M6.4')]
final class ActivityResilienceInterceptorTest extends TestCase
{
    public function testSuccessPassesThrough(): void
    {
        $connection = self::createStub(Connection::class);
        $connection->method('isConnected')->willReturn(false);

        $result = (new ActivityResilienceInterceptor(new WorkerConnectionGuard($connection, new NullLogger())))
            ->handleActivityInbound($this->input(), static fn(ActivityInput $in): string => 'done');

        self::assertSame('done', $result);
    }

    public function testConnectionLossIsDroppedAndReportedAsRetryable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isConnected')->willReturn(false);
        $connection->expects(self::once())->method('close');
        $interceptor = new ActivityResilienceInterceptor(new WorkerConnectionGuard($connection, new NullLogger()));

        try {
            $interceptor->handleActivityInbound($this->input(), static function (ActivityInput $in): never {
                throw new ConnectionLost(new class ('gone') extends \Exception implements DriverExceptionInterface {
                    public function getSQLState(): string
                    {
                        return '08006';
                    }
                }, null);
            });
            self::fail('The failure must be rethrown.');
        } catch (ApplicationFailure $failure) {
            self::assertSame(ActivityResilienceInterceptor::FAILURE_TYPE, $failure->getType());
            self::assertFalse($failure->isNonRetryable(), 'a lost connection must be retried by Temporal');
            self::assertInstanceOf(ConnectionLost::class, $failure->getPrevious());
        }
    }

    public function testBusinessErrorKeepsItsType(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isConnected')->willReturn(false);
        $connection->expects(self::never())->method('close');

        $this->expectException(IllegalOrderTransition::class);
        (new ActivityResilienceInterceptor(new WorkerConnectionGuard($connection, new NullLogger())))
            ->handleActivityInbound($this->input(), static fn(ActivityInput $in): never => throw new IllegalOrderTransition(OrderStatus::Paid, OrderStatus::Pending));
    }

    /**
     * The transient type must never appear among the non-retryable names of any activity profile.
     */
    public function testTransientTypeIsNotListedAsNonRetryable(): void
    {
        $config = Yaml::parseFile(dirname(__DIR__, 4) . '/config/packages/temporal.yaml');
        self::assertIsArray($config);
        $parameters = $config['parameters'] ?? [];
        self::assertIsArray($parameters);
        $profiles = $parameters['temporal.activities'] ?? [];
        self::assertIsArray($profiles);

        foreach ($profiles as $name => $profile) {
            self::assertIsArray($profile);
            $listed = $profile['non_retryable'] ?? [];
            self::assertIsArray($listed);

            foreach ($listed as $type) {
                self::assertIsString($type);
                self::assertStringNotContainsString('Connection', $type, $name . ' lists a connection error as non-retryable');
                self::assertStringNotContainsString('DBAL', $type, $name . ' lists a database error as non-retryable');
                self::assertNotSame(ActivityResilienceInterceptor::FAILURE_TYPE, $type);
            }
        }

        self::assertGreaterThan(0, count($profiles));
        self::assertTrue(class_exists(SagaConfiguration::class));
    }

    private function input(): ActivityInput
    {
        return new ActivityInput(EncodedValues::empty(), Header::empty());
    }
}
