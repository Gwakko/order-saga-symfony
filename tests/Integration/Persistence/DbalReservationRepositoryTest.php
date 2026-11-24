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
}
