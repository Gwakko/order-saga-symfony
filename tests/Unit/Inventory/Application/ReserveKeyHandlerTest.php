<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Application;

use App\Inventory\Application\Command\ReserveKey\ReserveKeyCommand;
use App\Inventory\Application\Command\ReserveKey\ReserveKeyHandler;
use App\Inventory\Application\Exception\OutOfStock;
use App\Inventory\Domain\KeyStatus;
use App\Inventory\Domain\ReservationStatus;
use App\Shared\Domain\DomainErrorCode;
use App\Tests\Unit\Ordering\Domain\OrderFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * ReserveKey (EC-23 idempotent repeat, INV-05, out of stock).
 */
#[Group('M3.3')]
final class ReserveKeyHandlerTest extends TestCase
{
    private const OTHER_ORDER_ID = '0190a5c4-0000-7000-8000-0000000000b1';

    private function handler(InventoryWorld $w): ReserveKeyHandler
    {
        return new ReserveKeyHandler($w->keys, $w->reservations, $w->transactions, $w->ids, $w->clock);
    }

    public function testReservesAnAvailableKeyForTheTtl(): void
    {
        $w = new InventoryWorld();

        $output = ($this->handler($w))(new ReserveKeyCommand(OrderFactory::ORDER_ID, OrderFactory::PRODUCT_ID));

        self::assertSame(InventoryWorld::FIRST_KEY_ID, $output->keyId);
        self::assertSame($w->ids->peek(1), $output->reservationId);
        self::assertEquals(OrderFactory::t0()->modify('+900 seconds'), $output->expiresAt);
        self::assertSame(KeyStatus::Reserved, $w->key()->status);
        self::assertSame(ReservationStatus::Active, $w->reservations->findByOrder($w->orderId())?->status);
    }

    /**
     * EC-23: the answer was lost and the activity repeats: the same reservation, no second key.
     */
    public function testRepeatReturnsTheSameReservation(): void
    {
        $w = new InventoryWorld();
        $handler = $this->handler($w);
        $command = new ReserveKeyCommand(OrderFactory::ORDER_ID, OrderFactory::PRODUCT_ID);

        $first = $handler($command);
        $second = $handler($command);

        self::assertEquals($first, $second);
        self::assertSame(KeyStatus::Available, $w->key(InventoryWorld::SECOND_KEY_ID)->status);
    }

    public function testRepeatAfterConsumeStillReturnsTheSale(): void
    {
        $w = new InventoryWorld();
        $sale = $w->seedSale();

        $output = ($this->handler($w))(new ReserveKeyCommand(OrderFactory::ORDER_ID, OrderFactory::PRODUCT_ID));

        self::assertSame($sale->id->value, $output->reservationId);
        self::assertSame(KeyStatus::Sold, $w->key()->status);
    }

    public function testTwoOrdersGetTwoKeys(): void
    {
        $w = new InventoryWorld();
        $handler = $this->handler($w);

        $a = $handler(new ReserveKeyCommand(OrderFactory::ORDER_ID, OrderFactory::PRODUCT_ID));
        $b = $handler(new ReserveKeyCommand(self::OTHER_ORDER_ID, OrderFactory::PRODUCT_ID));

        self::assertNotSame($a->keyId, $b->keyId);
    }

    public function testNoKeyLeftIsOutOfStockAndNothingIsWritten(): void
    {
        $w = new InventoryWorld(availableKeys: 0);

        try {
            ($this->handler($w))(new ReserveKeyCommand(OrderFactory::ORDER_ID, OrderFactory::PRODUCT_ID));
            self::fail('Expected OutOfStock');
        } catch (OutOfStock $e) {
            self::assertSame(DomainErrorCode::OutOfStock, $e->code());
        }

        self::assertNull($w->reservations->findByOrder($w->orderId()));
    }

    public function testOnlyReservedKeysAreSkippedWhenPickingTheNextOne(): void
    {
        $w = new InventoryWorld(availableKeys: 1);
        $w->seedReservation();

        $this->expectException(OutOfStock::class);

        ($this->handler($w))(new ReserveKeyCommand(self::OTHER_ORDER_ID, OrderFactory::PRODUCT_ID));
    }
}
