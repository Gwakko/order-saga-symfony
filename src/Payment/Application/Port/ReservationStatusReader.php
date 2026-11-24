<?php

declare(strict_types=1);

namespace App\Payment\Application\Port;

use App\Shared\Domain\Identifier\OrderId;

/**
 * Read-only view on Inventory, declared by the consumer (Payment).
 */
interface ReservationStatusReader
{
    /**
     * Expiry of the order's `Active` reservation; null when there is none (consumed, released, expired, absent).
     */
    public function activeUntil(OrderId $orderId): ?\DateTimeImmutable;
}
