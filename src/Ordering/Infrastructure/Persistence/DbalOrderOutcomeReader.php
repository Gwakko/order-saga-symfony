<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence;

use App\Ordering\Domain\OrderStatus;
use App\Payment\Application\Port\OrderOutcomeReader;
use App\Shared\Domain\Identifier\OrderId;
use Doctrine\DBAL\Connection;

final readonly class DbalOrderOutcomeReader implements OrderOutcomeReader
{
    public function __construct(private Connection $connection) {}

    public function endedWithoutKey(OrderId $orderId): bool
    {
        $status = $this->connection->fetchOne('SELECT status FROM orders WHERE id = :id', ['id' => $orderId->value]);

        return in_array($status, [OrderStatus::Failed->value, OrderStatus::Cancelled->value], true);
    }
}
