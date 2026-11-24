<?php

declare(strict_types=1);

namespace App\Payment\Application\Port;

use App\Shared\Domain\Identifier\OrderId;

interface OrderOutcomeReader
{
    /**
     * True when the order ended without a delivered key (`Failed` or `Cancelled`): a late charge is then an orphan.
     */
    public function endedWithoutKey(OrderId $orderId): bool;
}
