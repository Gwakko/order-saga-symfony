<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Domain;

use App\Inventory\Domain\Event\KeyReleased;
use App\Inventory\Domain\Event\KeyReserved;
use App\Inventory\Domain\Event\KeySold;
use App\Inventory\Domain\Event\ReservationExpired;
use App\Inventory\Domain\Exception\IllegalReservationTransition;
use App\Inventory\Domain\Exception\InventoryInvariantViolated;
use App\Inventory\Domain\Exception\ReservationAlreadyConsumed;
use App\Inventory\Domain\Exception\ReservationHasExpired;
use App\Inventory\Domain\KeyReservation;
use App\Inventory\Domain\ReservationStatus;
use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Identifier\ProductKeyId;
use App\Shared\Domain\Identifier\ReservationId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * T-U-12: reservation transitions, inclusive expiry boundary, idempotent release/consume (EC-20, EC-22).
 */
#[Group('T-U-12')]
final class KeyReservationTest extends TestCase
{
    private static function order(): OrderId
    {
        return OrderId::fromString(InventoryFactory::ORDER_ID);
    }

    /**
     * @return array<string, KeyReservation> every status, reached through real transitions
     */
    private static function inEveryStatus(): array
    {
        $active = InventoryFactory::reservation();

        return [
            ReservationStatus::Active->value => $active,
            ReservationStatus::Consumed->value => $active->consume(self::order(), InventoryFactory::at(10)),
            ReservationStatus::Released->value => $active->release(InventoryFactory::at(10)),
            ReservationStatus::Expired->value => $active->expire(InventoryFactory::at(InventoryFactory::TTL)),
        ];
    }

    /**
     * @return iterable<string, array{ReservationStatus, ReservationStatus, bool}>
     */
    public static function transitionTable(): iterable
    {
        $allowed = [
            'active' => ['consumed', 'released', 'expired'],
            'consumed' => [],
            'released' => [],
            'expired' => [],
        ];

        foreach (ReservationStatus::cases() as $from) {
            foreach (ReservationStatus::cases() as $to) {
                yield $from->value . ' -> ' . $to->value => [$from, $to, in_array($to->value, $allowed[$from->value], true)];
            }
        }
    }

    #[DataProvider('transitionTable')]
    public function testStatusTableMatchesSpec(ReservationStatus $from, ReservationStatus $to, bool $expected): void
    {
        self::assertSame($expected, $from->canTransitionTo($to));
    }

    /**
     * An aggregate method changes status (version + 1) exactly when the table allows the move; time is chosen
     * so that both expiry-dependent methods have a chance (consume before, expire after the deadline).
     */
    #[DataProvider('transitionTable')]
    public function testAggregateMatchesSpec(ReservationStatus $from, ReservationStatus $to, bool $expected): void
    {
        $order = self::inEveryStatus()[$from->value];
        $beforeDeadline = InventoryFactory::at(InventoryFactory::TTL - 1);
        $afterDeadline = InventoryFactory::at(InventoryFactory::TTL);

        $attempts = match ($to) {
            ReservationStatus::Active => [],
            ReservationStatus::Consumed => [fn(): KeyReservation => $order->consume(self::order(), $beforeDeadline)],
            ReservationStatus::Released => [fn(): KeyReservation => $order->release($beforeDeadline)],
            ReservationStatus::Expired => [fn(): KeyReservation => $order->expire($afterDeadline)],
        };

        $reached = false;

        foreach ($attempts as $attempt) {
            try {
                $result = $attempt();
            } catch (DomainError) {
                continue;
            }

            $reached = $reached || ($result->status === $to && $result->version === $order->version + 1);
        }

        self::assertSame($expected, $reached);
    }

    public function testCreateIsActiveWithExpiryAndEvent(): void
    {
        $reservation = InventoryFactory::reservation();

        self::assertSame(ReservationStatus::Active, $reservation->status);
        self::assertSame(1, $reservation->version);
        self::assertEquals(InventoryFactory::at(InventoryFactory::TTL), $reservation->expiresAt);
        self::assertCount(1, $reservation->pendingEvents);
        self::assertInstanceOf(KeyReserved::class, $reservation->pendingEvents[0]);
    }

    public function testNonPositiveTtlRejected(): void
    {
        $this->expectException(InventoryInvariantViolated::class);
        KeyReservation::create(
            ReservationId::fromString(InventoryFactory::RESERVATION_ID),
            self::order(),
            ProductKeyId::fromString(InventoryFactory::KEY_ID),
            ProductId::fromString(InventoryFactory::PRODUCT_ID),
            InventoryFactory::t0(),
            0,
        );
    }

    /**
     * EC-20: now == expiresAt means expired.
     */
    public function testExpiryBoundaryIsInclusive(): void
    {
        $reservation = InventoryFactory::reservation();
        $justBefore = InventoryFactory::at(InventoryFactory::TTL - 1);
        $atDeadline = InventoryFactory::at(InventoryFactory::TTL);

        // One second before the deadline: consume works, expire is refused.
        self::assertSame(ReservationStatus::Consumed, $reservation->consume(self::order(), $justBefore)->status);
        try {
            $reservation->expire($justBefore);
            self::fail('expire before the deadline must be refused');
        } catch (IllegalReservationTransition $e) {
            self::assertSame(DomainErrorCode::IllegalReservationTransition, $e->code());
        }

        // Exactly at the deadline: consume fails with ReservationExpired, expire is allowed.
        try {
            $reservation->consume(self::order(), $atDeadline);
            self::fail('consume at the deadline must fail');
        } catch (ReservationHasExpired $e) {
            self::assertSame(DomainErrorCode::ReservationExpired, $e->code());
        }
        self::assertSame(ReservationStatus::Expired, $reservation->expire($atDeadline)->status);
    }

    public function testConsumeAfterSweeperExpiredIsReservationExpired(): void
    {
        $expired = InventoryFactory::reservation()->expire(InventoryFactory::at(InventoryFactory::TTL + 5));

        $this->expectException(ReservationHasExpired::class);
        // Even if the clock of the caller lags behind the sweeper: a stored Expired status is final.
        $expired->consume(self::order(), InventoryFactory::at(1));
    }

    public function testConsumeIsIdempotentForTheSameOrderOnly(): void
    {
        $consumed = InventoryFactory::reservation()->consume(self::order(), InventoryFactory::at(10));

        self::assertSame($consumed, $consumed->consume(self::order(), InventoryFactory::at(20)));
        self::assertSame($consumed, $consumed->consume(self::order(), InventoryFactory::at(InventoryFactory::TTL + 100)));

        $this->expectException(IllegalReservationTransition::class);
        $consumed->consume(OrderId::fromString(InventoryFactory::OTHER_ORDER_ID), InventoryFactory::at(20));
    }

    public function testConsumeForAnotherOrderRejected(): void
    {
        $this->expectException(IllegalReservationTransition::class);
        InventoryFactory::reservation()->consume(OrderId::fromString(InventoryFactory::OTHER_ORDER_ID), InventoryFactory::at(10));
    }

    public function testReleasedCannotBeConsumed(): void
    {
        $released = InventoryFactory::reservation()->release(InventoryFactory::at(10));

        $this->expectException(IllegalReservationTransition::class);
        $released->consume(self::order(), InventoryFactory::at(20));
    }

    /**
     * EC-22: release of a consumed reservation is refused; released/expired are idempotent no-ops.
     */
    public function testReleaseRules(): void
    {
        $active = InventoryFactory::reservation();
        $released = $active->release(InventoryFactory::at(10));
        $expired = $active->expire(InventoryFactory::at(InventoryFactory::TTL));

        self::assertSame(ReservationStatus::Released, $released->status);
        self::assertSame($released, $released->release(InventoryFactory::at(20)));
        self::assertSame($expired, $expired->release(InventoryFactory::at(InventoryFactory::TTL + 1)));

        $consumed = $active->consume(self::order(), InventoryFactory::at(10));
        try {
            $consumed->release(InventoryFactory::at(20));
            self::fail('Expected ReservationAlreadyConsumed');
        } catch (ReservationAlreadyConsumed $e) {
            self::assertSame(DomainErrorCode::ReservationAlreadyConsumed, $e->code());
        }
    }

    public function testEveryTransitionRaisesOneMatchingEvent(): void
    {
        $active = InventoryFactory::reservation();
        $cases = [
            [$active->consume(self::order(), InventoryFactory::at(10)), KeySold::class],
            [$active->release(InventoryFactory::at(10)), KeyReleased::class],
            [$active->expire(InventoryFactory::at(InventoryFactory::TTL)), ReservationExpired::class],
        ];

        foreach ($cases as [$after, $eventClass]) {
            self::assertSame(2, $after->version);
            self::assertCount(2, $after->pendingEvents);
            $event = $after->pendingEvents[1];
            self::assertInstanceOf($eventClass, $event);
            self::assertSame(2, $event->aggregateVersion());
            self::assertSame(InventoryFactory::ORDER_ID, $event->orderId->value);
            self::assertSame(InventoryFactory::KEY_ID, $event->keyId->value);
        }
    }

    public function testIdempotentRepeatsRaiseNoEvent(): void
    {
        $released = InventoryFactory::reservation()->release(InventoryFactory::at(10));

        self::assertCount(2, $released->release(InventoryFactory::at(20))->pendingEvents);
    }
}
