<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Inventory\Application\Port\ReservationRepository;
use App\Inventory\Domain\KeyReservation;
use App\Inventory\Domain\ReservationStatus;
use App\Shared\Application\Exception\ConcurrencyConflict;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\ProductKeyId;

final class InMemoryReservationRepository implements ReservationRepository
{
    /** @var list<KeyReservation> */
    private array $reservations = [];

    public function findByOrder(OrderId $orderId): ?KeyReservation
    {
        $latest = null;

        foreach ($this->reservations as $reservation) {
            if (!$reservation->orderId->equals($orderId)) {
                continue;
            }

            if (in_array($reservation->status, [ReservationStatus::Active, ReservationStatus::Consumed], true)) {
                return $reservation;
            }

            $latest = $reservation;
        }

        return $latest;
    }

    public function findByKey(ProductKeyId $keyId): ?KeyReservation
    {
        $latest = null;

        foreach ($this->reservations as $reservation) {
            if ($reservation->keyId->equals($keyId)) {
                $latest = $reservation;
            }
        }

        return $latest;
    }

    public function findExpired(\DateTimeImmutable $now, int $limit): array
    {
        $found = array_values(array_filter(
            $this->reservations,
            static fn(KeyReservation $r): bool => $r->status === ReservationStatus::Active && $r->expiresAt <= $now,
        ));
        usort($found, static fn(KeyReservation $a, KeyReservation $b): int => $a->expiresAt <=> $b->expiresAt);

        return array_slice($found, 0, $limit);
    }

    public function save(KeyReservation $reservation): void
    {
        foreach ($this->reservations as $index => $stored) {
            if (!$stored->id->equals($reservation->id)) {
                continue;
            }

            if ($stored->version + 1 !== $reservation->version) {
                throw new ConcurrencyConflict('KeyReservation', $reservation->id->value);
            }

            $this->reservations[$index] = self::detach($reservation);

            return;
        }

        // INV-05: a second active/consumed reservation for one order loses the race.
        $existing = $this->findByOrder($reservation->orderId);

        if ($existing !== null && in_array($existing->status, [ReservationStatus::Active, ReservationStatus::Consumed], true)) {
            throw new ConcurrencyConflict('KeyReservation', $reservation->id->value);
        }

        $this->reservations[] = self::detach($reservation);
    }

    /**
     * Test setup: stores the reservation as if saved earlier; replaces one with the same id.
     */
    public function seed(KeyReservation $reservation): void
    {
        foreach ($this->reservations as $index => $stored) {
            if ($stored->id->equals($reservation->id)) {
                $this->reservations[$index] = self::detach($reservation);

                return;
            }
        }

        $this->reservations[] = self::detach($reservation);
    }

    private static function detach(KeyReservation $reservation): KeyReservation
    {
        return KeyReservation::reconstitute(
            $reservation->id,
            $reservation->orderId,
            $reservation->keyId,
            $reservation->productId,
            $reservation->status,
            $reservation->createdAt,
            $reservation->expiresAt,
            $reservation->version,
        );
    }
}
