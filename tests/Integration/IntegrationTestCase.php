<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Inventory\Domain\Product;
use App\Inventory\Domain\ProductKey;
use App\Ordering\Domain\Order;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Identifier\ProductKeyId;
use App\Shared\Domain\KeyCode;
use App\Shared\Domain\Money;
use App\Shared\Infrastructure\Id\SymfonyUuidGenerator;
use App\Shared\Infrastructure\Persistence\DbTimestamp;
use App\Tests\Integration\Support\Infra;
use App\Tests\Integration\Support\TemporalTestQueue;
use App\Tests\Integration\Support\TestDatabase;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Real Postgres. Every test starts on empty tables (TRUNCATE: concurrent tests need committed data).
 */
abstract class IntegrationTestCase extends KernelTestCase
{
    protected Connection $connection;
    protected Infra $infra;
    protected \DateTimeImmutable $t0;

    protected function setUp(): void
    {
        parent::setUp();

        TemporalTestQueue::isolate();
        self::bootKernel(['environment' => 'test', 'debug' => false]);
        $connection = self::getContainer()->get(Connection::class);

        $this->connection = $connection;
        TestDatabase::prepare(self::$kernel ?? throw new \LogicException('Kernel is not booted.'), $connection);
        TestDatabase::truncate($connection);

        $this->infra = new Infra($connection);
        $this->t0 = new \DateTimeImmutable('2026-05-01T10:00:00Z');
    }

    protected static function uuid(): string
    {
        return (new SymfonyUuidGenerator())->next();
    }

    protected function seedProduct(?ProductId $id = null, int $price = 1999, bool $active = true): Product
    {
        $product = new Product($id ?? ProductId::fromString(self::uuid()), 'Game', Money::positive($price, Currency::EUR), $active);
        $this->connection->executeStatement(
            'INSERT INTO products (id, name, price_amount, price_currency, active) VALUES (:id, :name, :amount, :currency, :active)',
            [
                'id' => $product->id->value,
                'name' => $product->name,
                'amount' => $product->price->amount,
                'currency' => $product->price->currency->value,
                'active' => $product->active,
            ],
            ['active' => \Doctrine\DBAL\ParameterType::BOOLEAN],
        );

        return $product;
    }

    /**
     * @return list<ProductKey>
     */
    protected function seedKeys(ProductId $productId, int $count): array
    {
        $keys = [];

        for ($i = 1; $i <= $count; ++$i) {
            $key = ProductKey::add(ProductKeyId::fromString(self::uuid()), $productId, new KeyCode(sprintf('KEY-%s-%04d', strtoupper(substr(md5($productId->value), 0, 6)), $i)));
            self::assertTrue($this->infra->keys->addIfAbsent($key));
            $keys[] = $key;
        }

        return $keys;
    }

    protected function placeOrder(?OrderId $id = null, ?ProductId $productId = null, ?CustomerId $customerId = null, int $price = 1999): Order
    {
        $money = Money::positive($price, Currency::EUR);

        return Order::place(
            $id ?? OrderId::fromString(self::uuid()),
            $customerId ?? CustomerId::fromString(self::uuid()),
            $productId ?? ProductId::fromString(self::uuid()),
            $money,
            $money,
            $this->t0,
        );
    }

    protected function countRows(string $table, string $where = 'TRUE'): int
    {
        $count = $this->connection->fetchOne(sprintf('SELECT count(*) FROM %s WHERE %s', $table, $where));

        return is_int($count) ? $count : throw new \UnexpectedValueException('count(*) is not an integer');
    }

    /**
     * Writes `$count` outbox rows with ids `0190a5c4-1000-7000-8000-<n>`, oldest first.
     *
     * @return list<string> message ids
     */
    protected function seedOutbox(int $count): array
    {
        $ids = [];

        for ($i = 1; $i <= $count; ++$i) {
            $id = sprintf('0190a5c4-1000-7000-8000-%012d', $i);
            $this->connection->executeStatement(
                "INSERT INTO outbox_messages (id, aggregate_type, aggregate_id, aggregate_version, event_type, payload, occurred_at)
                 VALUES (:id, 'order', :aggregate_id, 1, 'OrderPlaced', '{}', :occurred_at)",
                ['id' => $id, 'aggregate_id' => self::uuid(), 'occurred_at' => DbTimestamp::format($this->t0->modify(sprintf('+%d seconds', $i)))],
            );
            $ids[] = $id;
        }

        return $ids;
    }
}
