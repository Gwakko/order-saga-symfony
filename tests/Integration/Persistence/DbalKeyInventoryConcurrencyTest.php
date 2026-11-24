<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Inventory\Application\Command\ReserveKey\ReserveKeyCommand;
use App\Inventory\Application\Exception\OutOfStock;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\Support\ForkedRunner;
use App\Tests\Integration\Support\Infra;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;

/**
 * EC-15, INV-04: the last key goes to exactly one of two simultaneous buyers.
 */
#[Group('M4.2')]
#[Group('T-I-13')]
#[Group('concurrency')]
final class DbalKeyInventoryConcurrencyTest extends IntegrationTestCase
{
    public function testLastKeyReservedOnce(): void
    {
        for ($round = 1; $round <= 50; ++$round) {
            $this->connection->executeStatement('TRUNCATE key_reservations, product_keys, products, outbox_messages');
            $product = $this->seedProduct();
            [$key] = $this->seedKeys($product->id, 1);
            $productId = $product->id->value;

            $buyer = static function (Connection $connection) use ($productId): string {
                $handler = (new Infra($connection))->reserveKey(new MockClock('2026-05-01T10:00:00Z'));

                try {
                    $handler(new ReserveKeyCommand(self::uuid(), $productId));

                    return 'reserved';
                } catch (OutOfStock) {
                    return 'out_of_stock';
                }
            };

            $results = ForkedRunner::run($this->connection, [$buyer, $buyer]);
            sort($results);

            self::assertSame(['out_of_stock', 'reserved'], $results, sprintf('round %d', $round));
            self::assertSame(1, $this->countRows('key_reservations', "status = 'active'"), sprintf('round %d', $round));
            self::assertSame(1, $this->countRows('product_keys', sprintf("id = '%s' AND status = 'reserved'", $key->id->value)), sprintf('round %d', $round));
        }
    }

    public function testManyBuyersOfManyKeysNeverShareAKey(): void
    {
        $product = $this->seedProduct();
        $this->seedKeys($product->id, 4);
        $productId = $product->id->value;

        $buyer = static function (Connection $connection) use ($productId): string {
            $handler = (new Infra($connection))->reserveKey(new MockClock('2026-05-01T10:00:00Z'));

            try {
                return $handler(new ReserveKeyCommand(self::uuid(), $productId))->keyId;
            } catch (OutOfStock) {
                return 'out_of_stock';
            }
        };

        $results = ForkedRunner::run($this->connection, array_fill(0, 6, $buyer));
        $keys = array_values(array_filter($results, static fn(string $r): bool => $r !== 'out_of_stock'));

        self::assertCount(4, $keys, implode(', ', $results));
        self::assertCount(4, array_unique($keys));
        self::assertSame(2, count($results) - count($keys));
    }
}
