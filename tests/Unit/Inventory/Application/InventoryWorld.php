<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Application;

use App\Inventory\Domain\KeyReservation;
use App\Inventory\Domain\ProductKey;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Identifier\ProductKeyId;
use App\Shared\Domain\Identifier\ReservationId;
use App\Shared\Domain\KeyCode;
use App\Tests\Support\Fake\InMemoryKeyInventory;
use App\Tests\Support\Fake\InMemoryReservationRepository;
use App\Tests\Support\Fake\NoopTransactionManager;
use App\Tests\Support\Fake\RecordingLogger;
use App\Tests\Support\Fake\SequenceIdGenerator;
use App\Tests\Unit\Ordering\Domain\OrderFactory;
use Symfony\Component\Clock\MockClock;

/**
 * In-memory Inventory with one product and keys `AAAAA-00001` and up; the clock starts at t0 (`OrderFactory::t0()`).
 */
final class InventoryWorld
{
    public const FIRST_KEY_ID = '0190a5c4-3000-7000-8000-000000000001';
    public const SECOND_KEY_ID = '0190a5c4-3000-7000-8000-000000000002';

    public readonly InMemoryKeyInventory $keys;
    public readonly InMemoryReservationRepository $reservations;
    public readonly NoopTransactionManager $transactions;
    public readonly SequenceIdGenerator $ids;
    public readonly MockClock $clock;
    public readonly RecordingLogger $logger;

    public function __construct(int $availableKeys = 2)
    {
        $this->keys = new InMemoryKeyInventory();
        $this->reservations = new InMemoryReservationRepository();
        $this->transactions = new NoopTransactionManager();
        $this->ids = new SequenceIdGenerator();
        $this->clock = new MockClock('2026-05-01T10:00:00Z');
        $this->logger = new RecordingLogger();

        foreach (array_slice([self::FIRST_KEY_ID, self::SECOND_KEY_ID], 0, $availableKeys) as $i => $id) {
            $this->keys->seed(ProductKey::add(ProductKeyId::fromString($id), $this->productId(), new KeyCode(sprintf('AAAAA-0000%d', $i + 1))));
        }
    }

    public function productId(): ProductId
    {
        return ProductId::fromString(OrderFactory::PRODUCT_ID);
    }

    public function orderId(): OrderId
    {
        return OrderId::fromString(OrderFactory::ORDER_ID);
    }

    public function seedReservation(string $keyId = self::FIRST_KEY_ID, int $ttl = 900): KeyReservation
    {
        $reservation = KeyReservation::create(
            ReservationId::fromString(OrderFactory::RESERVATION_ID),
            $this->orderId(),
            ProductKeyId::fromString($keyId),
            $this->productId(),
            OrderFactory::t0(),
            $ttl,
        );
        $this->reservations->seed($reservation);
        $this->keys->seed($this->key($keyId)->reserve());

        return $reservation;
    }

    /**
     * A second active reservation, of another order, on the second key.
     */
    public function seedReservationForOtherOrder(): KeyReservation
    {
        $reservation = KeyReservation::create(
            ReservationId::fromString('0190a5c4-0000-7000-8000-0000000000b4'),
            OrderId::fromString('0190a5c4-0000-7000-8000-0000000000b1'),
            ProductKeyId::fromString(self::SECOND_KEY_ID),
            $this->productId(),
            OrderFactory::t0(),
            900,
        );
        $this->reservations->seed($reservation);
        $this->keys->seed($this->key(self::SECOND_KEY_ID)->reserve());

        return $reservation;
    }

    /**
     * An order that bought the key: reservation `Consumed`, key `Sold`.
     */
    public function seedSale(string $keyId = self::FIRST_KEY_ID): KeyReservation
    {
        $consumed = $this->seedReservation($keyId)->consume($this->orderId(), OrderFactory::later(10));
        $this->reservations->seed($consumed);
        $this->keys->seed($this->key($keyId)->sell());

        return $consumed;
    }

    public function key(string $keyId = self::FIRST_KEY_ID): ProductKey
    {
        return $this->keys->find(ProductKeyId::fromString($keyId)) ?? throw new \LogicException('unknown key');
    }
}
