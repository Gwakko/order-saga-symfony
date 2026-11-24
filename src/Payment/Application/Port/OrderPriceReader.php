<?php

declare(strict_types=1);

namespace App\Payment\Application\Port;

use App\Shared\Application\Exception\OrderNotFound;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Money;

/**
 * The charge amount comes from the stored order, never from the workflow input (INV-18, EC-81).
 */
interface OrderPriceReader
{
    /**
     * @throws OrderNotFound
     */
    public function priceOf(OrderId $orderId): Money;
}
