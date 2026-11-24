<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Shared\Domain\Identifier\OrderId;

interface OrderSagaStarter
{
    /**
     * Starts the fulfilment workflow `order-{orderId}`. An already started workflow counts as success (INV-16).
     */
    public function start(OrderId $orderId): void;
}
