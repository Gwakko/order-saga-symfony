<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence;

use App\Inventory\Application\Port\ProductReadModel;
use App\Inventory\Application\Query\GetProductAvailability\ProductAvailabilityOutput;
use App\Shared\Application\Output\MoneyOutput;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Infrastructure\Persistence\Row;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class DbalProductReadModel implements ProductReadModel
{
    public function __construct(private Connection $connection) {}

    public function find(ProductId $id): ?ProductAvailabilityOutput
    {
        $raw = $this->connection->fetchAssociative(
            'SELECT product_id, name, price_amount, price_currency, available FROM product_availability_view WHERE product_id = :id',
            ['id' => $id->value],
        );

        if ($raw === false) {
            return null;
        }

        return self::fromRow($raw);
    }

    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT product_id, name, price_amount, price_currency, available FROM product_availability_view WHERE product_id IN (:ids)',
            ['ids' => array_map(static fn(ProductId $id): string => $id->value, $ids)],
            ['ids' => ArrayParameterType::STRING],
        );

        return array_map(self::fromRow(...), $rows);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function fromRow(array $raw): ProductAvailabilityOutput
    {
        $row = new Row($raw);

        return new ProductAvailabilityOutput(
            $row->string('product_id'),
            $row->string('name'),
            new MoneyOutput($row->int('price_amount'), $row->string('price_currency')),
            max(0, $row->int('available')),
        );
    }
}
