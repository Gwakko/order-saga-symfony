<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Inventory\Application\Command\ReserveKey\ReserveKeyCommand;
use App\Inventory\Application\Exception\OutOfStock;
use App\Inventory\Domain\KeyStatus;
use App\Inventory\Domain\ProductKey;
use App\Shared\Application\Exception\ConcurrencyConflict;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Identifier\ProductKeyId;
use App\Shared\Domain\KeyCode;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\Support\ForkedRunner;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;

#[Group('M4.2')]
final class DbalKeyInventoryTest extends IntegrationTestCase
{
    #[Group('T-I-12')]
    public function testOutOfStock(): void
    {
        $product = $this->seedProduct();
        $handler = $this->infra->reserveKey(new MockClock($this->t0));

        $this->expectException(OutOfStock::class);
        $handler(new ReserveKeyCommand(self::uuid(), $product->id->value));
    }

    public function testOutOfStockLeavesNoReservation(): void
    {
        $product = $this->seedProduct();

        try {
            ($this->infra->reserveKey(new MockClock($this->t0)))(new ReserveKeyCommand(self::uuid(), $product->id->value));
            self::fail('OutOfStock expected');
        } catch (OutOfStock) {
            self::assertSame(0, $this->countRows('key_reservations'));
        }
    }

    public function testLockAvailableTakesOnlyAvailableKeysOfTheProduct(): void
    {
        $product = $this->seedProduct();
        $other = $this->seedProduct();
        [$reserved, $free] = $this->seedKeys($product->id, 2);
        $this->seedKeys($other->id, 1);
        $this->infra->keys->save($reserved->reserve());

        $locked = $this->infra->transactions->transactional(fn(): ?ProductKey => $this->infra->keys->lockAvailable($product->id));

        self::assertNotNull($locked);
        self::assertSame($free->id->value, $locked->id->value);
        self::assertSame(KeyStatus::Available, $locked->status);
    }

    public function testLockAvailableSkipsAKeyLockedByAnotherTransaction(): void
    {
        $product = $this->seedProduct();
        $this->seedKeys($product->id, 1);

        $this->infra->transactions->transactional(function () use ($product): void {
            self::assertNotNull($this->infra->keys->lockAvailable($product->id));

            $productId = $product->id->value;
            $result = ForkedRunner::run($this->connection, [
                static function (Connection $connection) use ($productId): string {
                    $infra = new \App\Tests\Integration\Support\Infra($connection);

                    return $infra->transactions->transactional(
                        static fn(): string => $infra->keys->lockAvailable(ProductId::fromString($productId)) === null ? 'none' : 'locked',
                    );
                },
            ]);

            self::assertSame(['none'], $result, 'a locked key must be skipped, not waited for');
        });
    }

    public function testLockAvailableOutsideATransactionIsRefused(): void
    {
        $product = $this->seedProduct();
        $this->seedKeys($product->id, 1);

        $this->expectException(\LogicException::class);
        $this->infra->keys->lockAvailable($product->id);
    }

    public function testAddIfAbsentIgnoresADuplicateCodeOfTheSameProduct(): void
    {
        $product = $this->seedProduct();
        $other = $this->seedProduct();
        $code = new KeyCode('SAME-CODE-0001');

        self::assertTrue($this->infra->keys->addIfAbsent(ProductKey::add(ProductKeyId::fromString(self::uuid()), $product->id, $code)));
        self::assertFalse($this->infra->keys->addIfAbsent(ProductKey::add(ProductKeyId::fromString(self::uuid()), $product->id, $code)));
        self::assertTrue($this->infra->keys->addIfAbsent(ProductKey::add(ProductKeyId::fromString(self::uuid()), $other->id, $code)));
        self::assertSame(2, $this->countRows('product_keys'));
    }

    public function testSaveIsACompareAndSwapOnTheVersion(): void
    {
        $product = $this->seedProduct();
        [$key] = $this->seedKeys($product->id, 1);

        $this->infra->keys->save($key->reserve());
        $stored = $this->infra->keys->find($key->id);
        self::assertNotNull($stored);
        self::assertSame(KeyStatus::Reserved, $stored->status);
        self::assertSame(2, $stored->version);

        $this->expectException(ConcurrencyConflict::class);
        $this->infra->keys->save($key->revoke(OrderId::fromString(self::uuid()), $this->t0));
    }

    public function testRevokingAKeyWritesItsEventToTheOutbox(): void
    {
        $product = $this->seedProduct();
        [$key] = $this->seedKeys($product->id, 1);

        $this->infra->keys->save($key->revoke(null, $this->t0));

        self::assertSame(1, $this->countRows('outbox_messages', "event_type = 'KeyRevoked' AND aggregate_type = 'key'"));
    }

    public function testFindReturnsNullForAnUnknownKey(): void
    {
        self::assertNull($this->infra->keys->find(ProductKeyId::fromString(self::uuid())));
    }
}
