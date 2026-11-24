<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment;

use App\Payment\Application\Exception\CircuitOpen;
use App\Payment\Application\Exception\PaymentGatewayUnavailable;
use App\Payment\Infrastructure\Gateway\CircuitBreaker;
use App\Payment\Infrastructure\Gateway\CircuitState;
use App\Payment\Infrastructure\Gateway\CircuitStateStoreUnavailable;
use App\Payment\Infrastructure\Gateway\RedisCircuitStateStore;
use App\Shared\Infrastructure\Redis\RedisConnection;
use App\Tests\Integration\Support\ForkedRunner;
use App\Tests\Support\Fake\RecordingLogger;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Breaker state in the real Redis, shared across processes (EC-33).
 */
#[Group('M4.5')]
final class RedisCircuitStateStoreTest extends TestCase
{
    private string $circuit;
    private RedisCircuitStateStore $store;

    protected function setUp(): void
    {
        $this->circuit = 'test-' . bin2hex(random_bytes(6));
        $this->store = new RedisCircuitStateStore(self::connection());
    }

    protected function tearDown(): void
    {
        $this->store->close($this->circuit);
    }

    #[Group('T-I-22')]
    #[Group('concurrency')]
    public function testSingleProbeAcrossProcesses(): void
    {
        $circuit = $this->circuit;
        $openedAt = (int) (new \DateTimeImmutable('2026-05-01T10:00:00Z'))->format('Uv');
        $this->store->open($circuit, $openedAt);

        // Every process sees the circuit 30 s after it opened: half-open. The probe takes long enough for all to overlap.
        $worker = static function (Connection $unused) use ($circuit): string {
            $breaker = new CircuitBreaker($circuit, new RedisCircuitStateStore(self::connection()), new MockClock('2026-05-01T10:00:30Z'), new RecordingLogger());

            try {
                return $breaker->call(static function (): string {
                    usleep(400_000);

                    return 'probe';
                });
            } catch (CircuitOpen) {
                return 'circuit_open';
            }
        };

        $results = ForkedRunner::run(self::database(), array_fill(0, 6, $worker));
        sort($results);

        self::assertSame(['circuit_open', 'circuit_open', 'circuit_open', 'circuit_open', 'circuit_open', 'probe'], $results);
        self::assertNull($this->store->openedAt($circuit), 'the successful probe closed the circuit for everybody');
    }

    #[Group('T-I-22')]
    public function testProbeIsHandedOutOnceAndExpiresAfterItsTtl(): void
    {
        self::assertTrue($this->store->tryAcquireProbe($this->circuit, 0, 200));
        self::assertFalse($this->store->tryAcquireProbe($this->circuit, 0, 200));

        usleep(300_000);

        self::assertTrue($this->store->tryAcquireProbe($this->circuit, 0, 200), 'a probe that never reported does not block the circuit forever');
    }

    #[Group('T-I-22')]
    #[Group('concurrency')]
    public function testFailureStreakAndWindowAreSharedAndAtomic(): void
    {
        $circuit = $this->circuit;
        $now = (int) (new \DateTimeImmutable('2026-05-01T10:00:00Z'))->format('Uv');

        $worker = static function (Connection $unused) use ($circuit, $now): string {
            $store = new RedisCircuitStateStore(self::connection());
            $last = 0;

            for ($i = 0; $i < 10; ++$i) {
                $last = $store->recordCall($circuit, true, $now + $i, 30_000)->consecutiveFailures;
            }

            return (string) $last;
        };

        ForkedRunner::run(self::database(), array_fill(0, 4, $worker));
        $stats = $this->store->recordCall($circuit, false, $now + 100, 30_000);

        self::assertSame(0, $stats->consecutiveFailures, 'a success resets the streak');
        self::assertSame(41, $stats->calls, '4 processes x 10 failures + 1 success, none lost');
        self::assertSame(40, $stats->failures);

        $later = $this->store->recordCall($circuit, true, $now + 31_000, 30_000);
        self::assertSame(1, $later->calls, 'calls older than the window are dropped');
        self::assertSame(1, $later->consecutiveFailures);
    }

    public function testOpenAndCloseAreVisibleToOtherConnectionsAndResetTheCounters(): void
    {
        $other = new RedisCircuitStateStore(self::connection());
        $this->store->recordCall($this->circuit, true, 1_000, 30_000);
        $this->store->open($this->circuit, 5_000);

        self::assertSame(5_000, $other->openedAt($this->circuit));
        self::assertSame(1, $this->store->recordCall($this->circuit, true, 6_000, 30_000)->calls, 'opening forgot the earlier calls');

        $other->close($this->circuit);
        self::assertNull($this->store->openedAt($this->circuit));
    }

    public function testBreakerOpensOnSharedStateThroughTwoBreakers(): void
    {
        $clock = new MockClock('2026-05-01T10:00:00Z');
        $logger = new RecordingLogger();
        $first = new CircuitBreaker($this->circuit, $this->store, $clock, $logger);
        $second = new CircuitBreaker($this->circuit, new RedisCircuitStateStore(self::connection()), $clock, $logger);

        for ($i = 0; $i < 5; ++$i) {
            try {
                ($i % 2 === 0 ? $first : $second)->call(static function (): string {
                    return self::failing();
                });
            } catch (PaymentGatewayUnavailable) {
            }
        }

        self::assertSame(CircuitState::Open, $first->state());
        self::assertSame(CircuitState::Open, $second->state());
    }

    #[Group('T-U-43')]
    public function testUnreachableRedisIsReportedAsStoreUnavailableAndTheBreakerDegrades(): void
    {
        $store = new RedisCircuitStateStore(new RedisConnection('redis://127.0.0.1:1', 0.2));

        try {
            $store->openedAt($this->circuit);
            self::fail('Expected CircuitStateStoreUnavailable.');
        } catch (CircuitStateStoreUnavailable $e) {
            self::assertInstanceOf(\RedisException::class, $e->getPrevious());
        }

        $logger = new RecordingLogger();
        $breaker = new CircuitBreaker($this->circuit, $store, new MockClock('2026-05-01T10:00:00Z'), $logger);

        self::assertSame('through', $breaker->call(static fn(): string => self::text('through')));
        self::assertTrue($logger->hasRecord('warning'));
    }

    private static function text(string $value): string
    {
        return $value;
    }

    private static function failing(): string
    {
        throw new PaymentGatewayUnavailable('503');
    }

    private static function connection(): RedisConnection
    {
        $url = $_SERVER['REDIS_URL'] ?? $_ENV['REDIS_URL'] ?? 'redis://redis:6379';

        return new RedisConnection(is_string($url) ? $url : 'redis://redis:6379', 1.0);
    }

    /**
     * ForkedRunner wants a database connection to clone the parameters from; the workers here only use Redis.
     */
    private static function database(): Connection
    {
        return DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => 'postgres',
            'port' => 5432,
            'dbname' => 'app',
            'user' => 'app',
            'password' => 'app',
        ]);
    }
}
