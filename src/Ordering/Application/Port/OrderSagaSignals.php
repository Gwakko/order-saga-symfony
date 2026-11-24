<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Shared\Domain\Identifier\OrderId;

interface OrderSagaSignals
{
    public function requestCancellation(OrderId $orderId): void;
}
