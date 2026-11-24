<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Ordering\Domain\Order;
use App\Ordering\Infrastructure\Persistence\DbalOrderRepository;
use App\Ordering\Infrastructure\Projection\OrderProjection;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\PaymentId;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Identifier\ProductKeyId;
use App\Shared\Domain\Identifier\ReservationId;
use App\Shared\Domain\KeyCode;
use App\Shared\Domain\Money;
use App\Shared\Infrastructure\Id\SymfonyUuidGenerator;
use App\Shared\Infrastructure\Outbox\DomainEventCodec;
use App\Shared\Infrastructure\Outbox\OutboxMessage;
use App\Shared\Infrastructure\Persistence\DbTimestamp;
use App\Shared\Infrastructure\Persistence\Row;
use App\Tests\Integration\Support\TestDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP in, real Postgres (`app_test`) and the real container behind it; every test starts on empty tables.
 */
abstract class FunctionalTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient(['environment' => 'test', 'debug' => false]);
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        TestDatabase::prepare(self::$kernel ?? throw new \LogicException('Kernel is not booted.'), $this->connection);
        TestDatabase::truncate($this->connection);
    }

    protected static function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface((new NativeClock('UTC'))->now());
    }

    protected static function str(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    protected static function uuid(): string
    {
        return (new SymfonyUuidGenerator())->next();
    }

    protected function seedProduct(int $price = 1999, string $currency = 'EUR', bool $active = true): string
    {
        $id = self::uuid();
        $this->connection->executeStatement(
            'INSERT INTO products (id, name, price_amount, price_currency, active) VALUES (:id, :name, :amount, :currency, :active)',
            ['id' => $id, 'name' => 'Game', 'amount' => $price, 'currency' => $currency, 'active' => $active],
            ['active' => ParameterType::BOOLEAN],
        );

        return $id;
    }

    /**
     * Stores an aggregate and feeds its events to the read-model projection, as the outbox consumer would.
     */
    protected function persist(Order $order): void
    {
        $repository = self::getContainer()->get(DbalOrderRepository::class);
        $projection = self::getContainer()->get(OrderProjection::class);
        $codec = self::getContainer()->get(DomainEventCodec::class);
        $repository->save($order);

        foreach ($order->pendingEvents as $event) {
            $projection($codec->toMessage($event));
        }
    }

    /**
     * Runs every unprojected order event of the outbox through the projection, as the consumer would (EC-54).
     */
    protected function projectOutbox(): void
    {
        $projection = self::getContainer()->get(OrderProjection::class);
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, aggregate_type, aggregate_id, aggregate_version, event_type, payload::text AS payload, occurred_at
             FROM outbox_messages WHERE aggregate_type = 'order' ORDER BY occurred_at, aggregate_version, id",
        );

        foreach ($rows as $raw) {
            $row = new Row($raw);
            /** @var array<string, mixed> $payload */
            $payload = json_decode($row->string('payload'), true, 512, JSON_THROW_ON_ERROR);
            $projection(new OutboxMessage(
                $row->string('id'),
                $row->string('aggregate_type'),
                $row->string('aggregate_id'),
                $row->int('aggregate_version'),
                $row->string('event_type'),
                $payload,
                DbTimestamp::format($row->time('occurred_at')),
            ));
        }
    }

    /**
     * A completed order with fresh ids whose every transition happened `$completedAt` (default: now).
     */
    protected function completedOrder(string $customerId, ?\DateTimeImmutable $completedAt = null): Order
    {
        $at = $completedAt ?? self::now();

        return Order::place(
            OrderId::fromString(self::uuid()),
            CustomerId::fromString($customerId),
            ProductId::fromString(self::uuid()),
            Money::positive(1999, Currency::EUR),
            Money::positive(1999, Currency::EUR),
            $at->modify('-3 minutes'),
        )
            ->markKeyReserved(ReservationId::fromString(self::uuid()), $at->modify('-2 minutes'))
            ->markPaid(PaymentId::fromString(self::uuid()), $at->modify('-1 minute'))
            ->complete(ProductKeyId::fromString(self::uuid()), new KeyCode('ABCD-EFGH-1234'), $at);
    }

    protected function pendingOrder(string $customerId, ?\DateTimeImmutable $at = null): Order
    {
        $money = Money::positive(1999, Currency::EUR);

        return Order::place(
            OrderId::fromString(self::uuid()),
            CustomerId::fromString($customerId),
            ProductId::fromString(self::uuid()),
            $money,
            $money,
            $at ?? self::now(),
        );
    }

    /**
     * @param array<string, string> $headers
     */
    protected function request(string $method, string $uri, ?string $customerId, string $body = '', array $headers = []): Response
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        if ($customerId !== null) {
            $server['HTTP_X_CUSTOMER_ID'] = $customerId;
        }

        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $this->client->request($method, $uri, [], [], $server, $body === '' ? null : $body);

        return $this->client->getResponse();
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function placeOrder(?string $customerId, array $body, ?string $idempotencyKey = 'key-1'): Response
    {
        $headers = $idempotencyKey === null ? [] : ['Idempotency-Key' => $idempotencyKey];

        return $this->request('POST', '/api/orders', $customerId, json_encode($body, JSON_THROW_ON_ERROR), $headers);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function json(Response $response): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? self::stringKeyed($decoded) : throw new \UnexpectedValueException('Response body is not a JSON object.');
    }

    /**
     * @param array<mixed, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $values): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    protected function countRows(string $table, string $where = 'TRUE'): int
    {
        $count = $this->connection->fetchOne(sprintf('SELECT count(*) FROM %s WHERE %s', $table, $where));

        return is_int($count) ? $count : throw new \UnexpectedValueException('count(*) is not an integer.');
    }

    /**
     * @return array<string, mixed>
     */
    protected static function orderBody(string $productId, int $amount = 1999, string $currency = 'EUR'): array
    {
        return ['productId' => $productId, 'expectedPrice' => ['amount' => $amount, 'currency' => $currency]];
    }

    protected static function productId(string $id): ProductId
    {
        return ProductId::fromString($id);
    }
}
