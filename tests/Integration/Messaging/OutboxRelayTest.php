<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messaging;

use App\Shared\Domain\Identifier\ReservationId;
use App\Shared\Infrastructure\Persistence\DbTimestamp;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\Support\RecordingOutboxPublisher;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;

#[Group('M4.3')]
final class OutboxRelayTest extends IntegrationTestCase
{
    #[Group('T-I-30')]
    public function testUnpublishedMessageIsSentAfterRestart(): void
    {
        // The order is committed; no relay is running (EC-50).
        $order = $this->placeOrder();
        $this->infra->orders->save($order);
        self::assertSame(1, $this->countRows('outbox_messages', 'published_at IS NULL'));

        // "Restart": a brand-new relay instance picks the row up.
        $publisher = new RecordingOutboxPublisher();
        $clock = new MockClock('2026-05-01T12:00:00Z');
        $published = $this->infra->relay($publisher, $clock)->publishBatch();

        self::assertSame(1, $published);
        $message = $publisher->sent[0];
        self::assertSame('OrderPlaced', $message->eventType);
        self::assertSame('order', $message->aggregateType);
        self::assertSame($order->id->value, $message->aggregateId);
        self::assertSame(1, $message->aggregateVersion);
        self::assertSame($order->customerId->value, $message->payload['customer_id']);
        self::assertSame($order->pendingEvents[0]->eventId()->value, $message->messageId);
        self::assertSame(1, $this->countRows('outbox_messages', 'published_at IS NOT NULL'));
        $markedAt = $this->connection->fetchOne('SELECT published_at FROM outbox_messages');
        self::assertIsString($markedAt);
        self::assertEquals($clock->now(), DbTimestamp::parse($markedAt));

        // Nothing is sent twice by the next round.
        self::assertSame(0, $this->infra->relay($publisher)->publishBatch());
        self::assertCount(1, $publisher->sent);
    }

    #[Group('T-I-32')]
    public function testCrashBeforeMarkPublishedCausesDuplicate(): void
    {
        $ids = $this->seedOutbox(2);

        $crashing = new RecordingOutboxPublisher();
        $crashing->crashAfterSending = $ids[1];

        try {
            $this->infra->relay($crashing)->publishBatch();
            self::fail('The relay should have crashed.');
        } catch (\RuntimeException) {
            // The first row was marked, the crashed one was sent but not marked.
        }

        self::assertSame($ids, $crashing->ids());
        self::assertSame(1, $this->countRows('outbox_messages', 'published_at IS NULL'));

        $after = new RecordingOutboxPublisher();
        self::assertSame(1, $this->infra->relay($after)->publishBatch());

        // The second message reached the transport twice: at least once, and the inbox absorbs the duplicate.
        self::assertSame([$ids[1]], $after->ids());
        self::assertCount(2, array_filter([...$crashing->ids(), ...$after->ids()], static fn(string $id): bool => $id === $ids[1]));
        self::assertSame(0, $this->countRows('outbox_messages', 'published_at IS NULL'));
    }

    public function testRowsBeforeAFailingOneAreMarkedAndTheFailureIsCountedAndRethrown(): void
    {
        $ids = $this->seedOutbox(3);
        $publisher = new RecordingOutboxPublisher();
        $publisher->failBefore = $ids[1];

        try {
            $this->infra->relay($publisher)->publishBatch();
            self::fail('The rejection must surface.');
        } catch (\RuntimeException $e) {
            self::assertSame('transport rejected the message', $e->getMessage());
        }

        self::assertSame([$ids[0]], $publisher->ids());
        self::assertSame(1, $this->countRows('outbox_messages', sprintf("id = '%s' AND published_at IS NOT NULL", $ids[0])));
        self::assertSame(1, $this->countRows('outbox_messages', sprintf("id = '%s' AND published_at IS NULL AND attempts = 1", $ids[1])));
        self::assertSame(1, $this->countRows('outbox_messages', sprintf("id = '%s' AND published_at IS NULL AND attempts = 0", $ids[2])));

        $publisher->failBefore = null;
        self::assertSame(2, $this->infra->relay($publisher)->publishBatch());
        self::assertSame([$ids[0], $ids[1], $ids[2]], $publisher->ids());
    }

    public function testMessagesGoOutInOccurrenceOrderAndBatchesAreBounded(): void
    {
        $ids = $this->seedOutbox(5);
        $publisher = new RecordingOutboxPublisher();
        $relay = $this->infra->relay($publisher);

        self::assertSame(2, $relay->publishBatch(2));
        self::assertSame(2, $relay->publishBatch(2));
        self::assertSame(1, $relay->publishBatch(2));
        self::assertSame(0, $relay->publishBatch(2));
        self::assertSame($ids, $publisher->ids());
    }

    public function testEveryDomainEventOfAFullFlowIsNormalised(): void
    {
        $order = $this->placeOrder();
        $this->infra->orders->save($order);
        $this->infra->orders->save($this->infra->orders->get($order->id)->markKeyReserved(ReservationId::fromString(self::uuid()), $this->t0));

        $publisher = new RecordingOutboxPublisher();
        $this->infra->relay($publisher)->publishBatch();

        self::assertSame(['OrderPlaced', 'OrderKeyReserved'], array_map(static fn($m): string => $m->eventType, $publisher->sent));
        self::assertSame([1, 2], array_map(static fn($m): int => $m->aggregateVersion, $publisher->sent));
    }
}
