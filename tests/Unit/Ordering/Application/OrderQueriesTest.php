<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\Exception\InvalidCursor;
use App\Ordering\Application\Query\GetOrder\GetOrderHandler;
use App\Ordering\Application\Query\GetOrder\GetOrderQuery;
use App\Ordering\Application\Query\GetOrder\OrderDetailsOutput;
use App\Ordering\Application\Query\ListCustomerOrders\ListCustomerOrdersHandler;
use App\Ordering\Application\Query\ListCustomerOrders\ListCustomerOrdersQuery;
use App\Ordering\Application\Query\ListCustomerOrders\OrderCursor;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Application\Exception\InvalidInput;
use App\Shared\Application\Exception\OrderNotFound;
use App\Shared\Application\Output\MoneyOutput;
use App\Shared\Domain\DomainErrorCode;
use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Domain\Identifier\OrderId;
use App\Tests\Support\Fake\InMemoryOrderReadModel;
use App\Tests\Unit\Ordering\Domain\OrderFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * GetOrder and ListCustomerOrders (02_use_cases.md section 4; EC-54, EC-70).
 */
#[Group('M3.4')]
final class OrderQueriesTest extends TestCase
{
    private const OTHER_CUSTOMER_ID = '0190a5c4-0000-7000-8000-0000000000c2';

    private InMemoryOrderReadModel $readModel;

    protected function setUp(): void
    {
        $this->readModel = new InMemoryOrderReadModel();
    }

    private static function orderId(int $n): string
    {
        return sprintf('0190a5c4-0000-7000-8000-0000000001%02d', $n);
    }

    private function seed(int $n, int $createdAtSeconds, OrderStatus $status = OrderStatus::Pending, ?string $keyCode = null, string $customerId = OrderFactory::CUSTOMER_ID): void
    {
        $this->readModel->seed(CustomerId::fromString($customerId), new OrderDetailsOutput(
            self::orderId($n),
            $status,
            new MoneyOutput(1999, 'EUR'),
            OrderFactory::PRODUCT_ID,
            null,
            false,
            $keyCode,
            OrderFactory::later($createdAtSeconds),
            OrderFactory::later($createdAtSeconds + 5),
            null,
        ));
    }

    private function list(?OrderStatus $status = null, int $limit = 20, ?string $cursor = null): \App\Ordering\Application\Query\ListCustomerOrders\ListCustomerOrdersOutput
    {
        return (new ListCustomerOrdersHandler($this->readModel))(new ListCustomerOrdersQuery(OrderFactory::CUSTOMER_ID, $status, $limit, $cursor));
    }

    /**
     * @param \App\Ordering\Application\Query\ListCustomerOrders\ListCustomerOrdersOutput $page
     *
     * @return list<string>
     */
    private static function ids(\App\Ordering\Application\Query\ListCustomerOrders\ListCustomerOrdersOutput $page): array
    {
        return array_map(static fn($i): string => $i->orderId, $page->items);
    }

    public function testGetOrderReturnsTheOwnersOrder(): void
    {
        $this->seed(1, 0, OrderStatus::Completed, 'ABCDE-FGHIJ-AB12');

        $output = (new GetOrderHandler($this->readModel))(new GetOrderQuery(self::orderId(1), OrderFactory::CUSTOMER_ID));

        self::assertSame(self::orderId(1), $output->orderId);
        self::assertSame('ABCDE-FGHIJ-AB12', $output->keyCode);
    }

    /**
     * EC-70: a foreign order is a 404 like a missing one.
     */
    public function testGetOrderOfAnotherCustomerIsNotFound(): void
    {
        $this->seed(1, 0);

        try {
            (new GetOrderHandler($this->readModel))(new GetOrderQuery(self::orderId(1), self::OTHER_CUSTOMER_ID));
            self::fail('Expected OrderNotFound');
        } catch (OrderNotFound $e) {
            self::assertSame(DomainErrorCode::OrderNotFound, $e->code());
        }
    }

    /**
     * EC-54: the view has not caught up with PlaceOrder yet.
     */
    public function testGetOrderNotYetInTheViewIsNotFound(): void
    {
        $this->expectException(OrderNotFound::class);

        (new GetOrderHandler($this->readModel))(new GetOrderQuery(self::orderId(9), OrderFactory::CUSTOMER_ID));
    }

    public function testListIsNewestFirstWithOrderIdAsTieBreaker(): void
    {
        $this->seed(1, 10);
        $this->seed(2, 30);
        $this->seed(3, 30);
        $this->seed(4, 20);

        $page = $this->list();

        self::assertSame([self::orderId(3), self::orderId(2), self::orderId(4), self::orderId(1)], self::ids($page));
        self::assertNull($page->nextCursor);
    }

    public function testListPagesThroughAllOrdersExactlyOnce(): void
    {
        foreach ([1, 2, 3, 4, 5] as $n) {
            $this->seed($n, $n * 10);
        }

        $first = $this->list(limit: 2);
        self::assertSame([self::orderId(5), self::orderId(4)], self::ids($first));
        self::assertNotNull($first->nextCursor);

        $second = $this->list(limit: 2, cursor: $first->nextCursor);
        self::assertSame([self::orderId(3), self::orderId(2)], self::ids($second));
        self::assertNotNull($second->nextCursor);

        $third = $this->list(limit: 2, cursor: $second->nextCursor);
        self::assertSame([self::orderId(1)], self::ids($third));
        self::assertNull($third->nextCursor);
    }

    public function testExactlyOnePageHasNoNextCursor(): void
    {
        $this->seed(1, 10);
        $this->seed(2, 20);

        self::assertNull($this->list(limit: 2)->nextCursor);
    }

    public function testListFiltersByStatusAndOwner(): void
    {
        $this->seed(1, 10, OrderStatus::Completed);
        $this->seed(2, 20, OrderStatus::Pending);
        $this->seed(3, 30, OrderStatus::Completed, customerId: self::OTHER_CUSTOMER_ID);

        self::assertSame([self::orderId(1)], self::ids($this->list(OrderStatus::Completed)));
        self::assertSame([self::orderId(2), self::orderId(1)], self::ids($this->list()));
    }

    public function testEmptyList(): void
    {
        $page = $this->list();

        self::assertSame([], $page->items);
        self::assertNull($page->nextCursor);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenCursors(): iterable
    {
        yield 'not base64' => ['!!!'];
        yield 'no separator' => [rtrim(strtr(base64_encode('2026-05-01T10:00:00.000000+00:00'), '+/', '-_'), '=')];
        yield 'bad date' => [rtrim(strtr(base64_encode('yesterday|' . OrderFactory::ORDER_ID), '+/', '-_'), '=')];
        yield 'bad id' => [rtrim(strtr(base64_encode('2026-05-01T10:00:00.000000+00:00|not-a-uuid'), '+/', '-_'), '=')];
        yield 'two separators' => [rtrim(strtr(base64_encode('2026-05-01T10:00:00.000000+00:00|a|b'), '+/', '-_'), '=')];
        yield 'empty' => [''];
    }

    #[DataProvider('brokenCursors')]
    public function testBrokenCursorIsRejected(string $cursor): void
    {
        try {
            $this->list(cursor: $cursor);
            self::fail('Expected InvalidCursor');
        } catch (InvalidCursor $e) {
            self::assertSame(DomainErrorCode::InvalidCursor, $e->code());
        }
    }

    public function testCursorRoundTripKeepsMicrosecondsAndNormalisesToUtc(): void
    {
        $createdAt = new \DateTimeImmutable('2026-05-01T12:00:00.123456+02:00');
        $cursor = new OrderCursor($createdAt, OrderId::fromString(OrderFactory::ORDER_ID));

        $decoded = OrderCursor::decode($cursor->encode());

        self::assertSame('2026-05-01T10:00:00.123456+00:00', $decoded->createdAt->format('Y-m-d\TH:i:s.uP'));
        self::assertSame(OrderFactory::ORDER_ID, $decoded->orderId->value);
        self::assertStringNotContainsString('=', $cursor->encode());
        self::assertSame(1, preg_match('/^[A-Za-z0-9_-]+$/', $cursor->encode()));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function badLimits(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'above max' => [101];
    }

    #[DataProvider('badLimits')]
    public function testLimitOutsideTheRangeIsRejected(int $limit): void
    {
        $this->expectException(InvalidInput::class);

        new ListCustomerOrdersQuery(OrderFactory::CUSTOMER_ID, null, $limit);
    }

    public function testDefaultLimitIsTwenty(): void
    {
        self::assertSame(20, (new ListCustomerOrdersQuery(OrderFactory::CUSTOMER_ID))->limit);
    }
}
