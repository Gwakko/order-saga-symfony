<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Domain\OrderStatus;
use App\Shared\Domain\Identifier\OrderId;

final readonly class StuckOrder
{
    public function __construct(public OrderId $orderId, public OrderStatus $status) {}
}
