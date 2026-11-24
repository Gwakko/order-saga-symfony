<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Inventory\Application\Command\ReserveKey\ReserveKeyCommand;
use App\Inventory\Domain\KeyStatus;
use App\Shared\Domain\Identifier\OrderId;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\Support\ForkedRunner;
use App\Tests\Integration\Support\Infra;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;

/**
 * EC-23, INV-05: a repeated `ReserveKey` (lost reply, parallel retry) returns the same hold and takes no second key.
 */
#[Group('M4.2')]
#[Group('T-I-16')]
final class ReserveKeyIdempotencyTest extends IntegrationTestCase
{
    public function testRepeatAfterALostReplyReturnsTheSameReservation(): void
    {
        $product = $this->seedProduct();
        $this->seedKeys($product->id, 3);
        $orderId = self::uuid();
        $handler = $this->infra->reserveKey(new MockClock($this->t0));

        $first = $handler(new ReserveKeyCommand($orderId, $product->id->value));
        $second = $handler(new ReserveKeyCommand($orderId, $product->id->value));

        self::assertSame($first->reservationId, $second->reservationId);
        self::assertSame($first->keyId, $second->keyId);
        self::assertSame(1, $this->countRows('key_reservations'));
        self::assertSame(1, $this->countRows('product_keys', "status = 'reserved'"));
        self::assertSame(2, $this->countRows('product_keys', "status = 'available'"));
        self::assertSame(1, $this->countRows('outbox_messages', "event_type = 'KeyReserved'"));
    }

    #[Group('concurrency')]
    public function testParallelRepeatsTakeOnlyOneKey(): void
    {
        for ($round = 1; $round <= 20; ++$round) {
            $this->connection->executeStatement('TRUNCATE key_reservations, product_keys, products, outbox_messages');
            $product = $this->seedProduct();
            $this->seedKeys($product->id, 3);
            $orderId = self::uuid();
            $productId = $product->id->value;

            $caller = static function (Connection $connection) use ($orderId, $productId): string {
                try {
                    return (new Infra($connection))->reserveKey(new MockClock('2026-05-01T10:00:00Z'))(new ReserveKeyCommand($orderId, $productId))->reservationId;
                } catch (\Throwable $e) {
                    return 'EXCEPTION ' . $e::class;
                }
            };

            $results = ForkedRunner::run($this->connection, [$caller, $caller]);
            $ok = array_values(array_filter($results, static fn(string $r): bool => !str_starts_with($r, 'EXCEPTION')));

            // A caller may lose the race (ConcurrencyConflict, retryable); nobody ever holds a second reservation.
            self::assertNotSame([], $ok, implode(' | ', $results));
            self::assertCount(1, array_unique($ok), implode(' | ', $results));
            foreach ($results as $result) {
                self::assertTrue(!str_starts_with($result, 'EXCEPTION') || str_contains($result, 'ConcurrencyConflict'), $result);
            }

            self::assertSame(1, $this->countRows('key_reservations', sprintf("order_id = '%s' AND status = 'active'", $orderId)));
            self::assertSame(1, $this->countRows('product_keys', "status = 'reserved'"), 'the loser must give its key back');
            self::assertSame(2, $this->countRows('product_keys', "status = 'available'"));

            // Retrying the loser now returns the winner's reservation.
            $again = $this->infra->reserveKey(new MockClock($this->t0))(new ReserveKeyCommand($orderId, $productId));
            self::assertSame($ok[0], $again->reservationId);
        }
    }

    public function testAReleasedOrderCanReserveAgain(): void
    {
        $product = $this->seedProduct();
        [$key] = $this->seedKeys($product->id, 1);
        $orderId = OrderId::fromString(self::uuid());
        $clock = new MockClock($this->t0);

        ($this->infra->reserveKey($clock))(new ReserveKeyCommand($orderId->value, $product->id->value));
        $reservation = $this->infra->reservations->findByOrder($orderId);
        self::assertNotNull($reservation);
        $this->infra->transactions->transactional(function () use ($reservation, $key): void {
            $this->infra->keys->save($key->reserve()->release());
            $this->infra->reservations->save($reservation->release($this->t0));
        });

        $again = ($this->infra->reserveKey($clock))(new ReserveKeyCommand($orderId->value, $product->id->value));

        self::assertNotSame($reservation->id->value, $again->reservationId);
        self::assertSame(KeyStatus::Reserved, $this->infra->keys->find($key->id)?->status);
    }
}
