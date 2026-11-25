<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Ordering\Domain\OrderStatus;
use App\Shared\Application\Exception\ConcurrencyConflict;
use App\Shared\Application\Exception\OrderNotFound;
use App\Shared\Domain\FailureReason;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\PaymentId;
use App\Shared\Domain\Identifier\ProductKeyId;
use App\Shared\Domain\Identifier\ReservationId;
use App\Shared\Domain\KeyCode;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('M4.2')]
final class DbalOrderRepositoryTest extends IntegrationTestCase
{
    public function testRoundTripOfAnOrderThatWentThroughTheWholeHappyPath(): void
    {
        $order = $this->placeOrder();
        $this->infra->orders->save($order);

        $loaded = $this->infra->orders->get($order->id);
        self::assertEquals($order->id, $loaded->id);
        self::assertSame(OrderStatus::Pending, $loaded->status);
        self::assertSame([], $loaded->pendingEvents);
        self::assertEquals($order->price, $loaded->price);

        $now = $this->t0->modify('+1 minute');
        $completed = $loaded
            ->markKeyReserved(ReservationId::fromString(self::uuid()), $now)
            ->markPaid(PaymentId::fromString(self::uuid()), $now)
            ->complete(ProductKeyId::fromString(self::uuid()), new KeyCode('ABCDE-12345-ZZ99'), $now);
        $this->infra->orders->save($completed);

        $stored = $this->infra->orders->get($order->id);
        self::assertSame(OrderStatus::Completed, $stored->status);
        self::assertSame(4, $stored->version);
        self::assertEquals($completed->reservationId, $stored->reservationId);
        self::assertEquals($completed->paymentId, $stored->paymentId);
        self::assertEquals($completed->deliveredKeyId, $stored->deliveredKeyId);
        self::assertEquals($now, $stored->completedAt);
        self::assertEquals($this->t0, $stored->createdAt);
        self::assertSame('UTC', $stored->createdAt->getTimezone()->getName());
    }

    public function testCompensationReasonAndFailureReasonSurvive(): void
    {
        $order = $this->placeOrder();
        $this->infra->orders->save($order);
        $now = $this->t0->modify('+1 minute');

        $reserved = $this->infra->orders->get($order->id)->markKeyReserved(ReservationId::fromString(self::uuid()), $now);
        $this->infra->orders->save($reserved);
        $compensating = $this->infra->orders->get($order->id)->startCompensation(FailureReason::PaymentDeclined, $now);
        $this->infra->orders->save($compensating);
        self::assertSame(FailureReason::PaymentDeclined, $this->infra->orders->get($order->id)->compensationReason);
    }

    public function testMissingOrderRaisesOrderNotFound(): void
    {
        $this->expectException(OrderNotFound::class);
        $this->infra->orders->get(OrderId::fromString(self::uuid()));
    }

    public function testStaleSaveIsRejectedAndLeavesNoTrace(): void
    {
        $order = $this->placeOrder();
        $this->infra->orders->save($order);

        $now = $this->t0->modify('+1 minute');
        $first = $this->infra->orders->get($order->id);
        $second = $this->infra->orders->get($order->id);

        $this->infra->orders->save($first->requestCancellation($now));
        $outboxBefore = $this->countRows('outbox_messages');

        try {
            $this->infra->orders->save($second->markKeyReserved(ReservationId::fromString(self::uuid()), $now));
            self::fail('A stale write must be rejected.');
        } catch (ConcurrencyConflict) {
            self::assertSame($outboxBefore, $this->countRows('outbox_messages'));
            $stored = $this->infra->orders->get($order->id);
            self::assertSame(OrderStatus::Pending, $stored->status);
            self::assertTrue($stored->cancellationRequested);
            self::assertSame(2, $stored->version);
        }
    }

    public function testSavingTheSameNewOrderTwiceIsAConflict(): void
    {
        $order = $this->placeOrder();
        $this->infra->orders->save($order);

        $this->expectException(ConcurrencyConflict::class);
        $this->infra->orders->save($order);
    }

    public function testEventsLandInTheOutboxWithTheAggregateVersion(): void
    {
        $order = $this->placeOrder();
        $this->infra->orders->save($order);
        $this->infra->orders->save($this->infra->orders->get($order->id)->requestCancellation($this->t0->modify('+1 minute')));

        /** @var list<array{event_type: string, aggregate_version: int, aggregate_id: string, published_at: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative('SELECT event_type, aggregate_version, aggregate_id, published_at FROM outbox_messages ORDER BY aggregate_version');

        self::assertSame(['OrderPlaced', 'OrderCancellationRequested'], array_column($rows, 'event_type'));
        self::assertSame([1, 2], array_column($rows, 'aggregate_version'));
        self::assertSame([$order->id->value, $order->id->value], array_column($rows, 'aggregate_id'));
        self::assertSame([null, null], array_column($rows, 'published_at'));
    }

    public function testKeyCodeNeverReachesTheOutbox(): void
    {
        $order = $this->placeOrder();
        $this->infra->orders->save($order);
        $now = $this->t0->modify('+1 minute');
        $done = $this->infra->orders->get($order->id)
            ->markKeyReserved(ReservationId::fromString(self::uuid()), $now)
            ->markPaid(PaymentId::fromString(self::uuid()), $now)
            ->complete(ProductKeyId::fromString(self::uuid()), new KeyCode('SECRET-CODE-1234'), $now);
        $this->infra->orders->save($done);

        $all = implode('', array_map(static fn(mixed $payload): string => is_string($payload) ? $payload : '', $this->connection->fetchFirstColumn('SELECT payload::text FROM outbox_messages')));
        self::assertStringNotContainsString('SECRET-CODE-1234', $all);
        self::assertStringContainsString('1234', $all);
    }

    /**
     * M8a: unlike Payment and Reservation, the Order CAS counts the pending events (`version - count(events)`), so an
     * aggregate that is continued after `save` is refused instead of re-publishing: a safe failure, no duplicate
     * outbox row (the handlers reload the order before every transition, see the report M8a).
     */
    #[Group('T-M8A-12')]
    public function testContinuingASavedOrderIsRefusedAndWritesNoDuplicateEvents(): void
    {
        $order = $this->placeOrder();
        $this->infra->orders->save($order);
        $continued = $order->markKeyReserved(ReservationId::fromString(self::uuid()), $this->t0->modify('+1 minute'));

        try {
            $this->infra->orders->save($continued);
            self::fail('a continued aggregate is refused');
        } catch (ConcurrencyConflict) {
            self::assertSame(1, $this->countRows('outbox_messages'));
            self::assertSame(1, $this->infra->orders->get($order->id)->version);
        }
    }

    #[Group('T-M8A-13')]
    public function testSavingAnOrderAfterLoadingItWritesOnlyTheNewEvents(): void
    {
        $order = $this->placeOrder();
        $this->infra->orders->save($order);

        $loaded = $this->infra->orders->get($order->id);
        $this->infra->orders->save($loaded->markKeyReserved(ReservationId::fromString(self::uuid()), $this->t0->modify('+1 minute')));

        $types = array_map(static fn(mixed $type): string => is_string($type) ? $type : '', $this->connection->fetchFirstColumn('SELECT event_type FROM outbox_messages ORDER BY aggregate_version'));
        self::assertSame(['OrderPlaced', 'OrderKeyReserved'], $types);
    }
}
