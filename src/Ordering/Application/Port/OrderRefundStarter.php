<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Shared\Domain\Identifier\OrderId;

interface OrderRefundStarter
{
    /**
     * Starts the refund workflow `refund-{orderId}`. An already started workflow counts as success: one refund per
     * order (INV-06, INV-16, EC-61).
     */
    public function start(OrderId $orderId): void;
}
