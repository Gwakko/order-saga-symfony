<?php

declare(strict_types=1);

namespace App\Inventory\Application\Port;

use App\Inventory\Domain\KeyReservation;
use App\Shared\Application\Exception\ConcurrencyConflict;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\ProductKeyId;

interface ReservationRepository
{
    /**
     * The order's `Active` or `Consumed` reservation (at most one, INV-05); otherwise its latest `Released`/`Expired` one.
     */
    public function findByOrder(OrderId $orderId): ?KeyReservation;

    /**
     * The most recent reservation of the key, whatever its status.
     */
    public function findByKey(ProductKeyId $keyId): ?KeyReservation;

    /**
     * `Active` reservations with `expiresAt <= $now`, oldest first.
     *
     * @return list<KeyReservation>
     */
    public function findExpired(\DateTimeImmutable $now, int $limit): array;

    /**
     * Insert for a new reservation, CAS on the version otherwise. A second active/consumed reservation of one order
     * is rejected by the partial unique index and surfaces as ConcurrencyConflict (INV-05).
     *
     * @throws ConcurrencyConflict
     */
    public function save(KeyReservation $reservation): void;
}
