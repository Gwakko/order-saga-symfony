<?php

declare(strict_types=1);

namespace App\Tests\Integration\Projection;

use App\Ordering\Domain\Event\OrderEvent;
use App\Ordering\Domain\Order;
use App\Ordering\Infrastructure\Projection\OrderProjection;
use App\Ordering\Infrastructure\Projection\OrderViewNotReady;
use App\Shared\Domain\Identifier\ReservationId;
use App\Shared\Infrastructure\Outbox\DomainEventCodec;
use App\Shared\Infrastructure\Outbox\OutboxMessage;
use App\Shared\Infrastructure\Persistence\DbTimestamp;
use App\Shared\Infrastructure\Persistence\Row;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Unit\Ordering\Domain\OrderFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * `order_view` built from order events: versions decide, repeats and stale events do nothing (EC-55, INV-15).
 */
#[Group('M4.6')]
final class OrderProjectionTest extends IntegrationTestCase
{
    private OrderProjection $projection;
    private DomainEventCodec $codec;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projection = self::getContainer()->get(OrderProjection::class);
        $this->codec = self::getContainer()->get(DomainEventCodec::class);
    }

    #[Group('T-I-34')]
    public function testStaleVersionIgnored(): void
    {
        $order = OrderFactory::paid();
        [$placed, $reserved, $paid] = $order->pendingEvents;
        self::assertSame([1, 2, 3], [$placed->version, $reserved->version, $paid->version]);

        // v3 overtakes v2 on the way: the view takes v3, and v2 arriving afterwards changes nothing.
        $this->project($placed);
        $this->project($paid);
        self::assertSame(['paid', 3], $this->statusAndVersion($order));

        $this->project($reserved);
        self::assertSame(['paid', 3], $this->statusAndVersion($order), 'v2 after v3 is ignored');
        self::assertSame(3, $this->countRows('inbox_messages', "consumer = 'order_projection'"), 'the stale message was still acknowledged');
    }

    public function testRepeatedMessageIsAppliedOnce(): void
    {
        $order = OrderFactory::keyReserved();
        [$placed, $reserved] = $order->pendingEvents;

        $this->project($placed);
        $message = $this->codec->toMessage($reserved);
        ($this->projection)($message);
        $this->connection->executeStatement("UPDATE order_view SET status = 'pending', version = 1");
        ($this->projection)($message);

        self::assertSame(['pending', 1], $this->statusAndVersion($order), 'the inbox swallowed the repeat, nothing was re-applied');
        self::assertSame(2, $this->countRows('inbox_messages', "consumer = 'order_projection'"));
    }

    public function testEventBeforeOrderPlacedIsRetriedLater(): void
    {
        $order = OrderFactory::keyReserved();
        [$placed, $reserved] = $order->pendingEvents;

        try {
            ($this->projection)($this->codec->toMessage($reserved));
            self::fail('Expected OrderViewNotReady.');
        } catch (OrderViewNotReady) {
            self::addToAssertionCount(1);
        }

        self::assertSame(0, $this->countRows('inbox_messages'), 'a failed attempt leaves no inbox row: the retry will run');

        $this->project($placed);
        $this->project($reserved);
        self::assertSame(['key_reserved', 2], $this->statusAndVersion($order));
    }

    public function testMessagesOfOtherAggregatesAreIgnored(): void
    {
        ($this->projection)(new OutboxMessage(self::uuid(), 'payment', self::uuid(), 1, 'PaymentSucceeded', [], '2026-05-01 10:00:00.000000+00:00'));

        self::assertSame(0, $this->countRows('order_view'));
        self::assertSame(0, $this->countRows('inbox_messages'));
    }

    /**
     * @return iterable<string, array{Order}>
     */
    public static function lifecycles(): iterable
    {
        yield 'pending' => [OrderFactory::pending()];
        yield 'key reserved' => [OrderFactory::keyReserved()];
        yield 'paid' => [OrderFactory::paid()];
        yield 'completed' => [OrderFactory::completed()];
        yield 'compensating' => [OrderFactory::compensating()];
        yield 'compensating after cancel request' => [OrderFactory::compensating(true)];
        yield 'cancelled' => [OrderFactory::cancelled()];
        yield 'failed' => [OrderFactory::failed()];
        yield 'refunding' => [OrderFactory::refunding()];
        yield 'refunded' => [OrderFactory::refunded()];
        yield 'manual review after compensation' => [OrderFactory::manualReviewAfterCompensation()];
        yield 'manual review after refund' => [OrderFactory::manualReviewAfterRefund()];
    }

    #[DataProvider('lifecycles')]
    public function testViewEqualsTheAggregateAfterEveryLifecycle(Order $order): void
    {
        foreach ($order->pendingEvents as $event) {
            $this->project($event);
        }

        $raw = $this->connection->fetchAssociative('SELECT * FROM order_view WHERE order_id = :id', ['id' => $order->id->value]);
        self::assertIsArray($raw);
        $row = new Row($raw);

        self::assertSame($order->status->value, $row->string('status'));
        self::assertSame($order->failureReason?->value, $row->nullableString('failure_reason'));
        self::assertSame($order->cancellationRequested, $row->bool('cancellation_requested'));
        self::assertSame($order->deliveredKeyId?->value, $row->nullableString('delivered_key_id'));
        self::assertSame($order->version, $row->int('version'));
        self::assertSame($order->customerId->value, $row->string('customer_id'));
        self::assertSame($order->productId->value, $row->string('product_id'));
        self::assertSame($order->price->amount, $row->int('price_amount'));
        self::assertSame($order->price->currency->value, $row->string('price_currency'));
        self::assertSame(DbTimestamp::format($order->createdAt), DbTimestamp::format($row->time('created_at')));
        self::assertSame(DbTimestamp::format($order->updatedAt), DbTimestamp::format($row->time('updated_at')));
        self::assertSame(DbTimestamp::formatNullable($order->completedAt), DbTimestamp::formatNullable($row->nullableTime('completed_at')));
    }

    public function testOrderEventsInReverseOrderConvergeOnceThePlacedEventIsThere(): void
    {
        $order = OrderFactory::completed();
        $events = $order->pendingEvents;
        $placed = array_shift($events);
        self::assertNotNull($placed);

        $this->project($placed);

        foreach (array_reverse($events) as $event) {
            $this->project($event);
        }

        self::assertSame(['completed', $order->version], $this->statusAndVersion($order));
    }

    private function project(OrderEvent $event): void
    {
        ($this->projection)($this->codec->toMessage($event));
    }

    /**
     * @return array{string, int}
     */
    private function statusAndVersion(Order $order): array
    {
        $raw = $this->connection->fetchAssociative('SELECT status, version FROM order_view WHERE order_id = :id', ['id' => $order->id->value]);
        self::assertIsArray($raw);
        $row = new Row($raw);

        return [$row->string('status'), $row->int('version')];
    }
}
