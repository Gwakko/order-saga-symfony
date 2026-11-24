<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Application;

use App\Inventory\Application\Command\AddProductKeys\AddProductKeysCommand;
use App\Inventory\Application\Command\AddProductKeys\AddProductKeysHandler;
use App\Inventory\Application\Command\ExpireReservations\ExpireReservationsCommand;
use App\Inventory\Application\Command\ExpireReservations\ExpireReservationsHandler;
use App\Inventory\Application\Command\RevokeKey\RevokeKeyCommand;
use App\Inventory\Application\Command\RevokeKey\RevokeKeyHandler;
use App\Inventory\Application\Exception\KeyNotFound;
use App\Inventory\Application\Exception\ProductNotFound;
use App\Inventory\Application\Query\GetProductAvailability\GetProductAvailabilityHandler;
use App\Inventory\Application\Query\GetProductAvailability\GetProductAvailabilityQuery;
use App\Inventory\Application\Query\GetProductAvailability\ProductAvailabilityOutput;
use App\Inventory\Domain\KeyStatus;
use App\Inventory\Domain\ReservationStatus;
use App\Shared\Application\Exception\InvalidInput;
use App\Shared\Application\Output\MoneyOutput;
use App\Shared\Domain\Exception\InvalidKeyCode;
use App\Tests\Support\Fake\InMemoryProductReadModel;
use App\Tests\Unit\Ordering\Domain\OrderFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * ExpireReservations, AddProductKeys, RevokeKey, GetProductAvailability (02_use_cases.md sections 3, 4.3).
 */
#[Group('M3.4')]
final class InventoryMaintenanceTest extends TestCase
{
    private function expire(InventoryWorld $w, int $limit = 100): int
    {
        return (new ExpireReservationsHandler($w->keys, $w->reservations, $w->transactions, $w->clock))(new ExpireReservationsCommand($limit))->expiredCount;
    }

    public function testExpiresReservationsPastTheirTimeAndFreesTheKeys(): void
    {
        $w = new InventoryWorld();
        $w->seedReservation();
        $w->clock->modify('2026-05-01T10:15:00Z');

        self::assertSame(1, $this->expire($w));
        self::assertSame(ReservationStatus::Expired, $w->reservations->findByOrder($w->orderId())?->status);
        self::assertSame(KeyStatus::Available, $w->key()->status);
    }

    public function testReservationsStillInTimeAreKept(): void
    {
        $w = new InventoryWorld();
        $w->seedReservation();
        $w->clock->modify('2026-05-01T10:14:59Z');

        self::assertSame(0, $this->expire($w));
        self::assertSame(ReservationStatus::Active, $w->reservations->findByOrder($w->orderId())?->status);
        self::assertSame(KeyStatus::Reserved, $w->key()->status);
    }

    public function testConsumedReservationsAreNeverExpired(): void
    {
        $w = new InventoryWorld();
        $w->seedSale();
        $w->clock->modify('2026-05-02T10:00:00Z');

        self::assertSame(0, $this->expire($w));
        self::assertSame(KeyStatus::Sold, $w->key()->status);
    }

    public function testRepeatedSweepIsANoOp(): void
    {
        $w = new InventoryWorld();
        $w->seedReservation();
        $w->clock->modify('2026-05-01T11:00:00Z');

        self::assertSame(1, $this->expire($w));
        self::assertSame(0, $this->expire($w));
    }

    public function testSweepRespectsTheLimit(): void
    {
        $w = new InventoryWorld();
        $w->seedReservation();
        $w->seedReservationForOtherOrder();
        $w->clock->modify('2026-05-01T11:00:00Z');

        self::assertSame(1, $this->expire($w, 1));
        self::assertSame(1, $this->expire($w, 1));
        self::assertSame(0, $this->expire($w, 1));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function badLimits(): iterable
    {
        yield 'zero' => [0];
        yield 'too many' => [1001];
    }

    #[DataProvider('badLimits')]
    public function testSweepLimitIsRangeChecked(int $limit): void
    {
        $this->expectException(InvalidInput::class);

        new ExpireReservationsCommand($limit);
    }

    private function addKeys(InventoryWorld $w, string ...$codes): \App\Inventory\Application\Command\AddProductKeys\AddProductKeysOutput
    {
        return (new AddProductKeysHandler($w->keys, $w->transactions, $w->ids))(new AddProductKeysCommand(OrderFactory::PRODUCT_ID, array_values($codes)));
    }

    public function testAddsKeysAndCountsDuplicates(): void
    {
        $w = new InventoryWorld(availableKeys: 0);

        $first = $this->addKeys($w, 'AAAAA-00001', 'AAAAA-00002', 'AAAAA-00001');
        $second = $this->addKeys($w, 'AAAAA-00002', 'AAAAA-00003');

        self::assertSame(2, $first->added);
        self::assertSame(1, $first->duplicates);
        self::assertSame(1, $second->added);
        self::assertSame(1, $second->duplicates);
        self::assertSame(3, $w->keys->count());
    }

    public function testInvalidCodeIsRejected(): void
    {
        $w = new InventoryWorld(availableKeys: 0);

        $this->expectException(InvalidKeyCode::class);

        $this->addKeys($w, 'AAAAA-00001', 'bad code');
    }

    public function testEmptyBatchIsRejected(): void
    {
        $this->expectException(InvalidInput::class);

        new AddProductKeysCommand(OrderFactory::PRODUCT_ID, []);
    }

    /**
     * EC-18: the supplier revokes a reserved key.
     */
    public function testRevokeKeyOfAReservedKey(): void
    {
        $w = new InventoryWorld();
        $w->seedReservation();
        $handler = new RevokeKeyHandler($w->keys, $w->reservations, $w->transactions, $w->clock);

        $handler(new RevokeKeyCommand(InventoryWorld::FIRST_KEY_ID));
        $handler(new RevokeKeyCommand(InventoryWorld::FIRST_KEY_ID));

        self::assertSame(KeyStatus::Revoked, $w->key()->status);
        self::assertSame(ReservationStatus::Active, $w->reservations->findByOrder($w->orderId())?->status);
    }

    public function testRevokeKeyOfAnUnreservedAndOfASoldKey(): void
    {
        $w = new InventoryWorld();
        $w->seedSale();
        $handler = new RevokeKeyHandler($w->keys, $w->reservations, $w->transactions, $w->clock);

        $handler(new RevokeKeyCommand(InventoryWorld::FIRST_KEY_ID));
        $handler(new RevokeKeyCommand(InventoryWorld::SECOND_KEY_ID));

        self::assertSame(KeyStatus::Revoked, $w->key()->status);
        self::assertSame(KeyStatus::Revoked, $w->key(InventoryWorld::SECOND_KEY_ID)->status);
    }

    public function testRevokeUnknownKeyIsReported(): void
    {
        $w = new InventoryWorld();

        $this->expectException(KeyNotFound::class);

        (new RevokeKeyHandler($w->keys, $w->reservations, $w->transactions, $w->clock))(new RevokeKeyCommand('0190a5c4-3000-7000-8000-0000000000ff'));
    }

    public function testProductAvailability(): void
    {
        $readModel = new InMemoryProductReadModel();
        $readModel->put(new ProductAvailabilityOutput(OrderFactory::PRODUCT_ID, 'Game key', new MoneyOutput(1999, 'EUR'), 7));
        $handler = new GetProductAvailabilityHandler($readModel);

        $output = $handler(new GetProductAvailabilityQuery(OrderFactory::PRODUCT_ID));

        self::assertSame('Game key', $output->name);
        self::assertSame(7, $output->available);
        self::assertSame(1999, $output->price->amount);

        $this->expectException(ProductNotFound::class);

        $handler(new GetProductAvailabilityQuery('0190a5c4-0000-7000-8000-0000000000f3'));
    }
}
