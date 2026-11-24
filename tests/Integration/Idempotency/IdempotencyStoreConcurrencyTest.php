<?php

declare(strict_types=1);

namespace App\Tests\Integration\Idempotency;

use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Infrastructure\Idempotency\DbalIdempotencyStore;
use App\Shared\Infrastructure\Idempotency\IdempotencyKey;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\Support\ForkedRunner;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;

#[Group('M5.2')]
final class IdempotencyStoreConcurrencyTest extends IntegrationTestCase
{
    private const string HASH = 'a3f5c2d1e4b6978a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f708192a3b4c5d6';

    /**
     * EC-02: two sessions insert the same key at the same moment (barrier); one wins, the other is told it is in progress.
     */
    #[Group('T-I-01')]
    #[Group('concurrency')]
    public function testOnlyOneInsertWins(): void
    {
        for ($round = 1; $round <= 30; ++$round) {
            $this->connection->executeStatement('TRUNCATE idempotency_keys');
            $customer = self::uuid();

            $results = ForkedRunner::run($this->connection, [$this->begin($customer, $this->t0), $this->begin($customer, $this->t0)]);

            sort($results);
            self::assertSame(['InProgress', 'Started'], $results, sprintf('round %d', $round));
            self::assertSame(1, $this->countRows('idempotency_keys'), sprintf('round %d: one row', $round));
        }
    }

    #[Group('concurrency')]
    public function testOnlyOneOfManyInsertsWins(): void
    {
        for ($round = 1; $round <= 10; ++$round) {
            $this->connection->executeStatement('TRUNCATE idempotency_keys');
            $customer = self::uuid();

            $results = ForkedRunner::run($this->connection, array_fill(0, 8, $this->begin($customer, $this->t0)));

            self::assertCount(1, array_filter($results, static fn(string $r): bool => $r === 'Started'), sprintf('round %d: %s', $round, implode(',', $results)));
            self::assertCount(7, array_filter($results, static fn(string $r): bool => $r === 'InProgress'), sprintf('round %d: %s', $round, implode(',', $results)));
            self::assertSame(1, $this->countRows('idempotency_keys'));
        }
    }

    /**
     * Several retries find the same expired row at once: the row is replaced exactly once and exactly one retry runs.
     */
    #[Group('concurrency')]
    public function testOnlyOneRetryReplacesAnExpiredKey(): void
    {
        for ($round = 1; $round <= 20; ++$round) {
            $this->connection->executeStatement('TRUNCATE idempotency_keys');
            $customer = self::uuid();
            $store = new DbalIdempotencyStore($this->connection);
            $store->begin(CustomerId::fromString($customer), IdempotencyKey::fromHeader('key-1'), self::HASH, 'POST', '/api/orders', $this->t0);
            $store->complete(CustomerId::fromString($customer), IdempotencyKey::fromHeader('key-1'), 201, '{}');

            $later = $this->t0->modify('+25 hours');
            $results = ForkedRunner::run($this->connection, array_fill(0, 4, $this->begin($customer, $later)));

            self::assertCount(1, array_filter($results, static fn(string $r): bool => $r === 'Started'), sprintf('round %d: %s', $round, implode(',', $results)));
            self::assertCount(3, array_filter($results, static fn(string $r): bool => $r === 'InProgress'), sprintf('round %d: %s', $round, implode(',', $results)));
            self::assertSame(1, $this->countRows('idempotency_keys', "status = 'in_progress'"));
        }
    }

    /**
     * A winner that finishes while the others are still deciding turns them into replays, never into a second run.
     */
    #[Group('concurrency')]
    public function testWinnerCompletingMidRaceNeverLetsASecondRunStart(): void
    {
        for ($round = 1; $round <= 20; ++$round) {
            $this->connection->executeStatement('TRUNCATE idempotency_keys');
            $customer = self::uuid();
            $now = $this->t0;

            $job = static function (Connection $connection) use ($customer, $now): string {
                $store = new DbalIdempotencyStore($connection);
                $decision = $store->begin(CustomerId::fromString($customer), IdempotencyKey::fromHeader('key-1'), self::HASH, 'POST', '/api/orders', $now);

                if ($decision->outcome->name === 'Started') {
                    $store->complete(CustomerId::fromString($customer), IdempotencyKey::fromHeader('key-1'), 201, '{"orderId":"x"}');
                }

                return $decision->outcome->name;
            };

            $results = ForkedRunner::run($this->connection, array_fill(0, 4, $job));

            self::assertCount(1, array_filter($results, static fn(string $r): bool => $r === 'Started'), sprintf('round %d: %s', $round, implode(',', $results)));
            self::assertSame([], array_diff($results, ['Started', 'InProgress', 'Replay']), sprintf('round %d: %s', $round, implode(',', $results)));
            self::assertSame(1, $this->countRows('idempotency_keys', "status = 'completed'"));
        }
    }

    /**
     * @return \Closure(Connection): string
     */
    private function begin(string $customer, \DateTimeImmutable $now): \Closure
    {
        return static fn(Connection $connection): string => (new DbalIdempotencyStore($connection))
            ->begin(CustomerId::fromString($customer), IdempotencyKey::fromHeader('key-1'), self::HASH, 'POST', '/api/orders', $now)
            ->outcome->name;
    }
}
