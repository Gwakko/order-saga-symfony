<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Application;

use App\Inventory\Application\Command\ReleaseReservation\ReleaseReservationCommand;
use App\Inventory\Application\Command\ReleaseReservation\ReleaseReservationHandler;
use App\Inventory\Domain\KeyStatus;
use App\Inventory\Domain\ReservationStatus;
use App\Tests\Unit\Ordering\Domain\OrderFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * ReleaseReservation compensation (EC-22, 03_saga.md section 4).
 */
final class ReleaseReservationHandlerTest extends TestCase
{
    private function release(InventoryWorld $w): bool
    {
        $handler = new ReleaseReservationHandler($w->keys, $w->reservations, $w->transactions, $w->clock, $w->logger);

        return $handler(new ReleaseReservationCommand(OrderFactory::ORDER_ID))->released;
    }

    public function testActiveReservationIsReleasedAndKeyReturnsToStock(): void
    {
        $w = new InventoryWorld();
        $w->seedReservation();

        self::assertTrue($this->release($w));
        self::assertSame(ReservationStatus::Released, $w->reservations->findByOrder($w->orderId())?->status);
        self::assertSame(KeyStatus::Available, $w->key()->status);
        self::assertFalse($w->logger->hasRecord('warning'));
    }

    /**
     * EC-22: a sold key is not put back on sale.
     */
    #[Group('T-U-32')]
    public function testConsumedReturnsFalseAndWarns(): void
    {
        $w = new InventoryWorld();
        $w->seedSale();

        self::assertFalse($this->release($w));
        self::assertSame(ReservationStatus::Consumed, $w->reservations->findByOrder($w->orderId())?->status);
        self::assertSame(KeyStatus::Sold, $w->key()->status);
        self::assertTrue($w->logger->hasRecord('warning'));
    }

    public function testRepeatAndMissingReservationAreNoOps(): void
    {
        $w = new InventoryWorld();

        self::assertTrue($this->release($w), 'no reservation');

        $w->seedReservation();
        self::assertTrue($this->release($w));
        self::assertTrue($this->release($w), 'already released');
        self::assertSame(KeyStatus::Available, $w->key()->status);
    }

    public function testExpiredReservationIsLeftToTheSweeper(): void
    {
        $w = new InventoryWorld();
        $w->seedReservation();
        $w->reservations->seed($w->reservations->findByOrder($w->orderId())?->expire(OrderFactory::later(901)) ?? throw new \LogicException());
        $w->keys->seed($w->key()->release());

        self::assertTrue($this->release($w));
        self::assertSame(ReservationStatus::Expired, $w->reservations->findByOrder($w->orderId())?->status);
        self::assertSame(KeyStatus::Available, $w->key()->status);
    }

    public function testRevokedKeyStaysRevoked(): void
    {
        $w = new InventoryWorld();
        $w->seedReservation();
        $w->keys->seed($w->key()->revoke(null, OrderFactory::later(30)));

        self::assertTrue($this->release($w));
        self::assertSame(ReservationStatus::Released, $w->reservations->findByOrder($w->orderId())?->status);
        self::assertSame(KeyStatus::Revoked, $w->key()->status);
    }
}
