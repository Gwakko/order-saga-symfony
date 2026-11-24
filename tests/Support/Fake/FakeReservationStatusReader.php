<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Inventory\Domain\ReservationStatus;
use App\Payment\Application\Port\ReservationStatusReader;
use App\Shared\Domain\Identifier\OrderId;

/**
 * Reads the in-memory Inventory data the way the DBAL read adapter will.
 */
final readonly class FakeReservationStatusReader implements ReservationStatusReader
{
    public function __construct(private InMemoryReservationRepository $reservations) {}

    public function activeUntil(OrderId $orderId): ?\DateTimeImmutable
    {
        $reservation = $this->reservations->findByOrder($orderId);

        return $reservation?->status === ReservationStatus::Active ? $reservation->expiresAt : null;
    }
}
