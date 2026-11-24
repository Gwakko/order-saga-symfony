<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence;

use App\Ordering\Application\Port\DeliveredKeyReader;
use App\Shared\Domain\Identifier\ProductKeyId;
use App\Shared\Domain\KeyCode;
use Doctrine\DBAL\Connection;

final readonly class DbalDeliveredKeyReader implements DeliveredKeyReader
{
    public function __construct(private Connection $connection) {}

    public function codeOf(ProductKeyId $keyId): ?KeyCode
    {
        $code = $this->connection->fetchOne('SELECT code FROM product_keys WHERE id = :id', ['id' => $keyId->value]);

        return is_string($code) ? new KeyCode($code) : null;
    }
}
