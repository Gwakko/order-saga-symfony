<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence;

use App\Payment\Application\Port\OrderPriceReader;
use App\Shared\Application\Exception\OrderNotFound;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Money;
use App\Shared\Infrastructure\Persistence\Row;
use Doctrine\DBAL\Connection;

/**
 * Payment's view on Ordering: the price of the stored order (INV-18).
 */
final readonly class DbalOrderPriceReader implements OrderPriceReader
{
    public function __construct(private Connection $connection) {}

    public function priceOf(OrderId $orderId): Money
    {
        $row = $this->connection->fetchAssociative(
            'SELECT price_amount, price_currency FROM orders WHERE id = :id',
            ['id' => $orderId->value],
        );

        if ($row === false) {
            throw new OrderNotFound($orderId);
        }

        $row = new Row($row);

        return Money::of($row->int('price_amount'), Currency::from($row->string('price_currency')));
    }
}
