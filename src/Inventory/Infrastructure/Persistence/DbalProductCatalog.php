<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence;

use App\Ordering\Application\Port\ProductCatalog;
use App\Ordering\Application\Port\ProductSnapshot;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Money;
use App\Shared\Infrastructure\Persistence\Row;
use Doctrine\DBAL\Connection;

/**
 * Ordering's `ProductCatalog` port over Inventory's `products` table.
 */
final readonly class DbalProductCatalog implements ProductCatalog
{
    public function __construct(private Connection $connection) {}

    public function find(ProductId $id): ?ProductSnapshot
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, price_amount, price_currency, active FROM products WHERE id = :id',
            ['id' => $id->value],
        );

        if ($row === false) {
            return null;
        }

        $row = new Row($row);

        return new ProductSnapshot(
            ProductId::fromString($row->string('id')),
            Money::positive($row->int('price_amount'), Currency::from($row->string('price_currency'))),
            $row->bool('active'),
        );
    }
}
