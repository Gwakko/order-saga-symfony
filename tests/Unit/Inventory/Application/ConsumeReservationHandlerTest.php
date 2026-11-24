<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Application;

use App\Inventory\Application\Command\ConsumeReservation\ConsumeReservationCommand;
use App\Inventory\Application\Command\ConsumeReservation\ConsumeReservationHandler;
use App\Inventory\Application\Exception\KeyIsRevoked;
use App\Inventory\Application\Exception\ReservationNotFound;
use App\Inventory\Domain\Exception\ReservationHasExpired;
use App\Inventory\Domain\KeyStatus;
use App\Inventory\Domain\ReservationStatus;
use App\Tests\Unit\Ordering\Domain\OrderFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * ConsumeReservation (INV-14 expiry boundary, EC-16, EC-18 revoked key, idempotent repeat).
 */
#[Group('M3.3')]
final class ConsumeReservationHandlerTest extends TestCase
{
    private function consume(InventoryWorld $w): string
    {
        $handler = new ConsumeReservationHandler($w->keys, $w->reservations, $w->transactions, $w->clock);

        return $handler(new ConsumeReservationCommand(OrderFactory::ORDER_ID))->keyId;
    }

    public function testConsumesTheReservationAndSellsTheKey(): void
    {
        $w = new InventoryWorld();
        $w->seedReservation();
        $w->clock->modify('2026-05-01T10:05:00Z');

        self::assertSame(InventoryWorld::FIRST_KEY_ID, $this->consume($w));
        self::assertSame(ReservationStatus::Consumed, $w->reservations->findByOrder($w->orderId())?->status);
        self::assertSame(KeyStatus::Sold, $w->key()->status);
    }

    public function testRepeatIsIdempotent(): void
    {
        $w = new InventoryWorld();
        $w->seedReservation();

        $first = $this->consume($w);
        $second = $this->consume($w);

        self::assertSame($first, $second);
        self::assertSame(KeyStatus::Sold, $w->key()->status);
    }

    /**
     * INV-14: at `now == expiresAt` the hold is already over.
     */
    public function testExpiredAtTheBoundaryIsRejectedAndNothingChanges(): void
    {
        $w = new InventoryWorld();
        $w->seedReservation();
        $w->clock->modify('2026-05-01T10:15:00Z');

        try {
            $this->consume($w);
            self::fail('Expected ReservationHasExpired');
        } catch (ReservationHasExpired) {
        }

        self::assertSame(ReservationStatus::Active, $w->reservations->findByOrder($w->orderId())?->status);
        self::assertSame(KeyStatus::Reserved, $w->key()->status);
    }

    public function testSweptReservationIsExpired(): void
    {
        $w = new InventoryWorld();
        $w->reservations->seed($w->seedReservation()->expire(OrderFactory::later(901)));
        $w->clock->modify('2026-05-01T10:20:00Z');

        $this->expectException(ReservationHasExpired::class);

        $this->consume($w);
    }

    /**
     * EC-18: the supplier revoked the key while it was reserved.
     */
    public function testRevokedKeyIsReported(): void
    {
        $w = new InventoryWorld();
        $w->seedReservation();
        $w->keys->seed($w->key()->revoke(null, OrderFactory::later(30)));

        try {
            $this->consume($w);
            self::fail('Expected KeyIsRevoked');
        } catch (KeyIsRevoked $e) {
            self::assertSame(InventoryWorld::FIRST_KEY_ID, $e->keyId->value);
        }

        self::assertSame(ReservationStatus::Active, $w->reservations->findByOrder($w->orderId())?->status);
    }

    public function testNoReservationIsReported(): void
    {
        $this->expectException(ReservationNotFound::class);

        $this->consume(new InventoryWorld());
    }
}
