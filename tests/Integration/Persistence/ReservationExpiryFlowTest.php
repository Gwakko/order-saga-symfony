<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Inventory\Application\Command\ConsumeReservation\ConsumeReservationCommand;
use App\Inventory\Application\Command\ExpireReservations\ExpireReservationsCommand;
use App\Inventory\Application\Command\ReserveKey\ReserveKeyCommand;
use App\Inventory\Domain\Exception\ReservationHasExpired;
use App\Inventory\Domain\KeyStatus;
use App\Inventory\Domain\ReservationStatus;
use App\Shared\Domain\Identifier\OrderId;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;

/**
 * EC-16: the reservation lapses before payment; consuming it fails at the boundary, the sweeper frees the key.
 */
#[Group('M4.2')]
#[Group('T-I-14')]
final class ReservationExpiryFlowTest extends IntegrationTestCase
{
    public function testExpiredReservationCannotBeConsumedAndTheSweeperFreesTheKey(): void
    {
        $product = $this->seedProduct();
        [$key] = $this->seedKeys($product->id, 1);
        $orderId = self::uuid();

        $clock = new MockClock($this->t0);
        ($this->infra->reserveKey($clock, 900))(new ReserveKeyCommand($orderId, $product->id->value));

        // One second before the expiry the hold still counts, so the sweeper leaves it alone.
        $clock->modify('+899 seconds');
        self::assertSame(0, ($this->infra->expireReservations($clock))(new ExpireReservationsCommand(10))->expiredCount);

        // At `expiresAt` exactly it is over (INV-14).
        $clock->modify('+1 second');

        try {
            ($this->infra->consumeReservation($clock))(new ConsumeReservationCommand($orderId));
            self::fail('Consuming at expiresAt must fail.');
        } catch (ReservationHasExpired) {
            // expected
        }

        self::assertSame(1, ($this->infra->expireReservations($clock))(new ExpireReservationsCommand(10))->expiredCount);

        $reservation = $this->infra->reservations->findByOrder(OrderId::fromString($orderId));
        self::assertNotNull($reservation);
        self::assertSame(ReservationStatus::Expired, $reservation->status);
        self::assertSame(KeyStatus::Available, $this->infra->keys->find($key->id)?->status);
        self::assertSame(1, $this->countRows('outbox_messages', "event_type = 'ReservationExpired'"));

        // The freed key can be sold to the next order.
        $next = self::uuid();
        $out = ($this->infra->reserveKey($clock, 900))(new ReserveKeyCommand($next, $product->id->value));
        self::assertSame($key->id->value, $out->keyId);
    }

    public function testConsumeBeforeExpirySellsTheKey(): void
    {
        $product = $this->seedProduct();
        [$key] = $this->seedKeys($product->id, 1);
        $orderId = self::uuid();
        $clock = new MockClock($this->t0);

        ($this->infra->reserveKey($clock, 900))(new ReserveKeyCommand($orderId, $product->id->value));
        $clock->modify('+899 seconds');
        ($this->infra->consumeReservation($clock))(new ConsumeReservationCommand($orderId));

        self::assertSame(KeyStatus::Sold, $this->infra->keys->find($key->id)?->status);
        self::assertSame(ReservationStatus::Consumed, $this->infra->reservations->findByOrder(OrderId::fromString($orderId))?->status);
        self::assertSame(1, $this->countRows('outbox_messages', "event_type = 'KeySold'"));
    }
}
