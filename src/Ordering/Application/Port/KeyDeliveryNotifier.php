<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Domain\Identifier\OrderId;

interface KeyDeliveryNotifier
{
    /**
     * Tells the customer the key is ready. The notification never carries the key code (INV-17).
     */
    public function notify(OrderId $orderId, CustomerId $customerId): void;
}
