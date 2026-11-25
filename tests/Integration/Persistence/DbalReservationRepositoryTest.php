<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Inventory\Domain\KeyReservation;
use App\Inventory\Domain\ReservationStatus;
use App\Shared\Application\Exception\ConcurrencyConflict;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Identifier\ProductKeyId;
use App\Shared\Domain\Identifier\ReservationId;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('M4.2')]
final class DbalReservationRepositoryTest extends IntegrationTestCase
{
    private function reservation(?OrderId $orderId = null, ?ProductKeyId $keyId = null, ?\DateTimeImmutable $at = null): KeyReservation
    {
        return KeyReservation::create(
            ReservationId::fromString(self::uuid()),
            $orderId ?? OrderId::fromString(self::uuid()),
            $keyId ?? ProductKeyId::fromString(self::uuid()),
            ProductId::fromString(self::uuid()),
            $at ?? $this->t0,
            900,
        );
    }

    private function reload(OrderId $orderId): KeyReservation
    {
        return $this->infra->reservations->findByOrder($orderId) ?? throw new \LogicException('reservation is missing');
    }

    public function testSavedReservationRoundTrips(): void
    {
        $reservation = $this->reservation();
        $this->infra->reservations->save($reservation);

        $stored = $this->infra->reservations->findByOrder($reservation->orderId);

        self::assertNotNull($stored);
        self::assertEquals($reservation->id, $stored->id);
        self::assertSame(ReservationStatus::Active, $stored->status);
        self::assertEquals($this->t0->modify('+900 seconds'), $stored->expiresAt);
        self::assertSame([], $stored->pendingEvents);
        self::assertSame($reservation->id->value, $this->infra->reservations->findByKey($reservation->keyId)?->id->value);
    }

    public function testSecondActiveReservationOfTheKeyIsAConflictAndLeavesNoOutboxRow(): void
    {
        $keyId = ProductKeyId::fromString(self::uuid());
        $this->infra->reservations->save($this->reservation(keyId: $keyId));

        $this->expectException(ConcurrencyConflict::class);

        try {
            $this->infra->reservations->save($this->reservation(keyId: $keyId));
        } finally {
            self::assertSame(1, $this->countRows('outbox_messages'));
        }
    }

    public function testSecondLiveReservationOfTheOrderIsAConflict(): void
    {
        $orderId = OrderId::fromString(self::uuid());
        $this->infra->reservations->save($this->reservation($orderId));

        $this->expectException(ConcurrencyConflict::class);
        $this->infra->reservations->save($this->reservation($orderId));
    }

    public function testFindByOrderPrefersTheLiveReservationOverOlderReleasedOnes(): void
    {
        $orderId = OrderId::fromString(self::uuid());
        $old = $this->reservation($orderId, at: $this->t0);
        $this->infra->reservations->save($old);
        $this->infra->reservations->save($this->reload($orderId)->release($this->t0->modify('+1 minute')));
        $live = $this->reservation($orderId, at: $this->t0->modify('+2 minutes'));
        $this->infra->reservations->save($live);

        self::assertSame($live->id->value, $this->infra->reservations->findByOrder($orderId)?->id->value);

        $this->infra->reservations->save($this->reload($orderId)->release($this->t0->modify('+3 minutes')));
        self::assertSame($live->id->value, $this->infra->reservations->findByOrder($orderId)?->id->value, 'latest terminal one when none is live');
    }

    public function testFindExpiredReturnsOnlyActiveOnesAtOrPastTheirExpiryOldestFirst(): void
    {
        $late = $this->reservation(at: $this->t0->modify('+2 minutes'));
        $early = $this->reservation(at: $this->t0);
        $future = $this->reservation(at: $this->t0->modify('+1 hour'));
        $released = $this->reservation(at: $this->t0);
        foreach ([$late, $early, $future, $released] as $reservation) {
            $this->infra->reservations->save($reservation);
        }
        $this->infra->reservations->save($this->reload($released->orderId)->release($this->t0->modify('+1 minute')));

        $found = $this->infra->reservations->findExpired($this->t0->modify('+1020 seconds'), 10);

        self::assertSame([$early->id->value, $late->id->value], array_map(static fn(KeyReservation $r): string => $r->id->value, $found));
        self::assertCount(1, $this->infra->reservations->findExpired($this->t0->modify('+1020 seconds'), 1));
        self::assertSame([], $this->infra->reservations->findExpired($this->t0->modify('+899 seconds'), 10));
    }

    /**
     * M8a: the aggregate keeps the events of earlier saves, so continuing with it after `save` must publish only the
     * event of the new transition (the same defect as DbalPaymentRepository::save in M4.6: a duplicate outbox key).
     */
    #[Group('T-M8A-06')]
    public function testContinuingASavedReservationDoesNotRepublishEarlierEvents(): void
    {
        $created = $this->reservation();
        $this->infra->reservations->save($created);

        $consumed = $created->consume($created->orderId, $this->t0->modify('+1 minute'));
        $this->infra->reservations->save($consumed);

        self::assertSame(['KeyReserved', 'KeySold'], $this->outboxEventTypes());
        self::assertSame(ReservationStatus::Consumed, $this->reload($created->orderId)->status);
    }

    #[Group('T-M8A-07')]
    public function testSavingAReservationAfterLoadingItWritesOnlyTheNewEvent(): void
    {
        $created = $this->reservation();
        $this->infra->reservations->save($created);

        $this->infra->reservations->save($this->reload($created->orderId)->release($this->t0->modify('+1 minute')));

        self::assertSame(['KeyReserved', 'KeyReleased'], $this->outboxEventTypes());
    }

    #[Group('T-M8A-08')]
    public function testSavingAReservationTwiceWithTheSameEventsIsAConflictAndWritesNothingMore(): void
    {
        $created = $this->reservation();
        $this->infra->reservations->save($created);
        $released = $created->release($this->t0->modify('+1 minute'));
        $this->infra->reservations->save($released);

        try {
            $this->infra->reservations->save($released);
            self::fail('the second save of the same state must be refused');
        } catch (ConcurrencyConflict) {
            self::assertSame(['KeyReserved', 'KeyReleased'], $this->outboxEventTypes());
        }
    }

    /**
     * @return list<string>
     */
    private function outboxEventTypes(): array
    {
        return array_map(static fn(mixed $type): string => is_string($type) ? $type : '', $this->connection->fetchFirstColumn('SELECT event_type FROM outbox_messages ORDER BY aggregate_version'));
    }
}
