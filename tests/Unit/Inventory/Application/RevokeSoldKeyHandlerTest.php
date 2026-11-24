<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Application;

use App\Inventory\Application\Command\RevokeSoldKey\RevokeSoldKeyCommand;
use App\Inventory\Application\Command\RevokeSoldKey\RevokeSoldKeyHandler;
use App\Inventory\Application\Exception\ReservationNotFound;
use App\Inventory\Domain\KeyStatus;
use App\Tests\Unit\Ordering\Domain\OrderFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * RevokeSoldKey, the refund path (EC-63).
 */
final class RevokeSoldKeyHandlerTest extends TestCase
{
    private function revoke(InventoryWorld $w): string
    {
        $handler = new RevokeSoldKeyHandler($w->keys, $w->reservations, $w->transactions, $w->clock);

        return $handler(new RevokeSoldKeyCommand(OrderFactory::ORDER_ID))->keyId;
    }

    public function testRevokesTheSoldKey(): void
    {
        $w = new InventoryWorld();
        $w->seedSale();

        self::assertSame(InventoryWorld::FIRST_KEY_ID, $this->revoke($w));
        self::assertSame(KeyStatus::Revoked, $w->key()->status);
    }

    /**
     * EC-63: the key was revoked before the refund started; that is success, the refund goes on.
     */
    #[Group('T-U-38')]
    public function testAlreadyRevokedIsSuccess(): void
    {
        $w = new InventoryWorld();
        $w->seedSale();
        $w->keys->seed($w->key()->revoke($w->orderId(), OrderFactory::later(50)));
        $versionBefore = $w->key()->version;

        self::assertSame(InventoryWorld::FIRST_KEY_ID, $this->revoke($w));
        self::assertSame(KeyStatus::Revoked, $w->key()->status);
        self::assertSame($versionBefore, $w->key()->version, 'nothing is written twice');
    }

    public function testRepeatIsIdempotent(): void
    {
        $w = new InventoryWorld();
        $w->seedSale();

        $this->revoke($w);
        $this->revoke($w);

        self::assertSame(KeyStatus::Revoked, $w->key()->status);
    }

    public function testOrderWithoutReservationIsReported(): void
    {
        $this->expectException(ReservationNotFound::class);

        $this->revoke(new InventoryWorld());
    }
}
