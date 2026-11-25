<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Ordering\Domain\Order;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Money;
use App\Shared\Infrastructure\Http\ApiDateTime;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\Support\Infra;
use App\Tests\Integration\Support\TestDatabase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Clock\ClockInterface;

/**
 * EC-74, INV-12: the same instants are stored and returned whatever the PHP default timezone (`TZ`, `date.timezone`)
 * and the Postgres session timezone are. The scenario runs once under UTC and once under Europe/Kyiv (UTC+2 / +3,
 * the instant is the last night before the 2026 DST switch) and both snapshots must be identical.
 */
#[Group('M8.1')]
final class TimezoneIndependenceTest extends IntegrationTestCase
{
    private const string SQL_UTC = 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"';

    #[Group('T-I-40')]
    public function testStoredAndReturnedValuesDoNotDependOnTheSystemTimezone(): void
    {
        $originalTimezone = date_default_timezone_get();
        $originalEnv = getenv('TZ');

        try {
            $utc = $this->scenario('UTC');
            TestDatabase::truncate($this->connection);
            $kyiv = $this->scenario('Europe/Kyiv');
        } finally {
            date_default_timezone_set($originalTimezone);
            putenv($originalEnv === false ? 'TZ' : 'TZ=' . $originalEnv);
            $this->connection->executeStatement("SET TIME ZONE 'UTC'");
        }

        self::assertSame('2026-03-29T00:30:00.123456Z', $utc['dbCreatedAt'], 'the baseline is what we expect, not just equal to itself');
        self::assertSame($utc, $kyiv);
        self::assertSame('UTC', $kyiv['clockZone']);
        self::assertSame('2026-03-29T00:30:00.123+00:00', $kyiv['apiCreatedAt']);
        self::assertSame('2026-03-29T01:30:00.123456+00:00', $kyiv['loadedUpdatedAt']);
    }

    /**
     * @return array<string, string>
     */
    private function scenario(string $zone): array
    {
        date_default_timezone_set($zone);
        putenv('TZ=' . $zone);
        self::assertSame($zone, date_default_timezone_get(), 'the scenario really runs under this timezone');
        self::ensureKernelShutdown();
        self::bootKernel(['environment' => 'test', 'debug' => false]);
        $connection = $this->connection;
        $connection->executeStatement(sprintf("SET TIME ZONE '%s'", $zone));
        $infra = new Infra($connection);

        // 02:30 in Kyiv is 00:30 UTC; the local clock jumps from 03:00 to 04:00 half an hour later (the 2026 DST switch).
        $createdAt = new \DateTimeImmutable('2026-03-29 02:30:00.123456', new \DateTimeZone('Europe/Kyiv'));
        $money = Money::positive(1999, Currency::EUR);
        $order = Order::place(OrderId::fromString(self::uuid()), CustomerId::fromString(self::uuid()), ProductId::fromString(self::uuid()), $money, $money, $createdAt);
        $infra->orders->save($order);

        $loaded = $infra->orders->get($order->id);
        $clock = self::getContainer()->get(ClockInterface::class);

        return [
            'dbCreatedAt' => $this->text("SELECT to_char(created_at AT TIME ZONE 'UTC', '" . self::SQL_UTC . "') FROM orders"),
            'dbUpdatedAt' => $this->text("SELECT to_char(updated_at AT TIME ZONE 'UTC', '" . self::SQL_UTC . "') FROM orders"),
            'dbOutboxOccurredAt' => $this->text("SELECT to_char(occurred_at AT TIME ZONE 'UTC', '" . self::SQL_UTC . "') FROM outbox_messages"),
            'loadedCreatedAt' => $loaded->createdAt->format('Y-m-d\TH:i:s.uP'),
            'loadedUpdatedAt' => $loaded->createdAt->modify('+1 hour')->format('Y-m-d\TH:i:s.uP'),
            'apiCreatedAt' => ApiDateTime::format($loaded->createdAt),
            'clockZone' => $clock->now()->getTimezone()->getName(),
        ];
    }

    private function text(string $sql): string
    {
        $value = $this->connection->fetchOne($sql);

        return is_string($value) ? $value : throw new \UnexpectedValueException('Expected a string from: ' . $sql);
    }
}
