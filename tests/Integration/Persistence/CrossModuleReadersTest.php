<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Inventory\Infrastructure\Persistence\DbalDeliveredKeyReader;
use App\Inventory\Infrastructure\Persistence\DbalProductCatalog;
use App\Inventory\Infrastructure\Persistence\DbalReservationStatusReader;
use App\Ordering\Infrastructure\Persistence\DbalOrderOutcomeReader;
use App\Ordering\Infrastructure\Persistence\DbalOrderPriceReader;
use App\Shared\Application\Exception\OrderNotFound;
use App\Shared\Domain\Currency;
use App\Shared\Domain\FailureReason;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Identifier\ProductKeyId;
use App\Shared\Domain\Money;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The read-only ports one module declares and another module's tables back.
 */
#[Group('M4.2')]
final class CrossModuleReadersTest extends IntegrationTestCase
{
    public function testOrderPriceReaderReturnsTheStoredPrice(): void
    {
        $order = $this->placeOrder(price: 4242);
        $this->infra->orders->save($order);

        $price = (new DbalOrderPriceReader($this->connection))->priceOf($order->id);

        self::assertTrue($price->equals(Money::positive(4242, Currency::EUR)));
    }

    public function testOrderPriceReaderRaisesForAMissingOrder(): void
    {
        $this->expectException(OrderNotFound::class);
        (new DbalOrderPriceReader($this->connection))->priceOf(OrderId::fromString(self::uuid()));
    }

    public function testOrderOutcomeReader(): void
    {
        $reader = new DbalOrderOutcomeReader($this->connection);
        $now = $this->t0->modify('+1 minute');

        $pending = $this->placeOrder();
        $failed = $this->placeOrder();
        $cancelled = $this->placeOrder();
        $this->infra->orders->save($pending);
        $this->infra->orders->save($failed);
        $this->infra->orders->save($cancelled);
        $this->infra->orders->save($this->infra->orders->get($failed->id)->fail(FailureReason::OutOfStock, $now));
        $this->infra->orders->save($this->infra->orders->get($cancelled->id)->cancelBeforeStart($now));

        self::assertFalse($reader->endedWithoutKey($pending->id));
        self::assertTrue($reader->endedWithoutKey($failed->id));
        self::assertTrue($reader->endedWithoutKey($cancelled->id));
        self::assertFalse($reader->endedWithoutKey(OrderId::fromString(self::uuid())));
    }

    public function testProductCatalog(): void
    {
        $active = $this->seedProduct(price: 1500);
        $inactive = $this->seedProduct(active: false);
        $catalog = new DbalProductCatalog($this->connection);

        $found = $catalog->find($active->id);
        self::assertNotNull($found);
        self::assertTrue($found->price->equals(Money::positive(1500, Currency::EUR)));
        self::assertTrue($found->active);
        self::assertFalse($catalog->find($inactive->id)?->active);
        self::assertNull($catalog->find(ProductId::fromString(self::uuid())));
    }

    public function testDeliveredKeyReader(): void
    {
        $product = $this->seedProduct();
        [$key] = $this->seedKeys($product->id, 1);
        $reader = new DbalDeliveredKeyReader($this->connection);

        self::assertTrue($reader->codeOf($key->id)?->equals($key->code));
        self::assertNull($reader->codeOf(ProductKeyId::fromString(self::uuid())));
    }

    public function testReservationStatusReaderSeesOnlyActiveHolds(): void
    {
        $product = $this->seedProduct();
        $this->seedKeys($product->id, 1);
        $orderId = self::uuid();
        $reader = new DbalReservationStatusReader($this->connection);

        self::assertNull($reader->activeUntil(OrderId::fromString($orderId)));

        $reserved = ($this->infra->reserveKey(new \Symfony\Component\Clock\MockClock($this->t0), 900))(
            new \App\Inventory\Application\Command\ReserveKey\ReserveKeyCommand($orderId, $product->id->value),
        );

        $until = $reader->activeUntil(OrderId::fromString($orderId));
        self::assertEquals($this->t0->modify('+900 seconds'), $until);
        self::assertEquals($reserved->expiresAt, $until);

        $this->connection->executeStatement("UPDATE key_reservations SET status = 'consumed'");
        self::assertNull($reader->activeUntil(OrderId::fromString($orderId)));
    }
}
