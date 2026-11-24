<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Inventory\Application\Command\ConsumeReservation\ConsumeReservationCommand;
use App\Inventory\Application\Command\ExpireReservations\ExpireReservationsCommand;
use App\Inventory\Application\Command\ReserveKey\ReserveKeyCommand;
use App\Inventory\Domain\KeyStatus;
use App\Inventory\Domain\ReservationStatus;
use App\Shared\Application\Exception\ConcurrencyConflict;
use App\Shared\Domain\Identifier\OrderId;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\Support\ForkedRunner;
use App\Tests\Integration\Support\Infra;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;

/**
 * EC-19a, INV-14: the sweeper and `ConsumeReservation` race for one reservation near `expiresAt`; the CAS lets exactly
 * one win, key and reservation always end up consistent.
 */
#[Group('M4.2')]
#[Group('T-I-15')]
final class ReservationCasTest extends IntegrationTestCase
{
    public function testStaleReservationWriteIsRejected(): void
    {
        $product = $this->seedProduct();
        $this->seedKeys($product->id, 1);
        $orderId = OrderId::fromString(self::uuid());
        ($this->infra->reserveKey(new MockClock($this->t0), 900))(new ReserveKeyCommand($orderId->value, $product->id->value));

        $loadedBySweeper = $this->infra->reservations->findByOrder($orderId);
        $loadedByConsumer = $this->infra->reservations->findByOrder($orderId);
        self::assertNotNull($loadedBySweeper);
        self::assertNotNull($loadedByConsumer);

        $this->infra->reservations->save($loadedByConsumer->consume($orderId, $this->t0->modify('+899 seconds')));

        $this->expectException(ConcurrencyConflict::class);
        $this->infra->reservations->save($loadedBySweeper->expire($this->t0->modify('+900 seconds')));
    }

    #[Group('concurrency')]
    public function testSweeperVsConsume(): void
    {
        $outcomes = ['consumed' => 0, 'expired' => 0];

        for ($round = 1; $round <= 30; ++$round) {
            $this->connection->executeStatement('TRUNCATE key_reservations, product_keys, products, outbox_messages');
            $product = $this->seedProduct();
            [$key] = $this->seedKeys($product->id, 1);
            $orderId = self::uuid();
            ($this->infra->reserveKey(new MockClock($this->t0), 900))(new ReserveKeyCommand($orderId, $product->id->value));

            $consumer = static function (Connection $connection) use ($orderId): string {
                try {
                    (new Infra($connection))->consumeReservation(new MockClock('2026-05-01T10:14:59Z'))(new ConsumeReservationCommand($orderId));

                    return 'consumed';
                } catch (\Throwable $e) {
                    return 'lost: ' . $e::class;
                }
            };
            $sweeper = static function (Connection $connection): string {
                $expired = (new Infra($connection))->expireReservations(new MockClock('2026-05-01T10:15:00Z'))(new ExpireReservationsCommand(10))->expiredCount;

                return $expired === 1 ? 'expired' : 'lost: swept ' . $expired;
            };

            $results = ForkedRunner::run($this->connection, [$consumer, $sweeper]);
            $winners = array_values(array_filter($results, static fn(string $r): bool => !str_starts_with($r, 'lost')));

            self::assertCount(1, $winners, sprintf('round %d: %s', $round, implode(' | ', $results)));

            $reservation = $this->infra->reservations->findByOrder(OrderId::fromString($orderId));
            $keyNow = $this->infra->keys->find($key->id);
            self::assertNotNull($reservation);
            self::assertNotNull($keyNow);

            if ($winners === ['consumed']) {
                self::assertSame(ReservationStatus::Consumed, $reservation->status);
                self::assertSame(KeyStatus::Sold, $keyNow->status);
                ++$outcomes['consumed'];
            } else {
                self::assertSame(ReservationStatus::Expired, $reservation->status);
                self::assertSame(KeyStatus::Available, $keyNow->status);
                ++$outcomes['expired'];
            }
        }

        self::assertSame(30, $outcomes['consumed'] + $outcomes['expired']);
    }
}
