<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence;

use App\Payment\Application\Port\ReservationStatusReader;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Infrastructure\Persistence\DbTimestamp;
use Doctrine\DBAL\Connection;

final readonly class DbalReservationStatusReader implements ReservationStatusReader
{
    public function __construct(private Connection $connection) {}

    public function activeUntil(OrderId $orderId): ?\DateTimeImmutable
    {
        $expiresAt = $this->connection->fetchOne(
            "SELECT expires_at FROM key_reservations WHERE order_id = :order_id AND status = 'active'",
            ['order_id' => $orderId->value],
        );

        return is_string($expiresAt) ? DbTimestamp::parse($expiresAt) : null;
    }
}
