<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\CancelOrder;

use App\Ordering\Domain\OrderStatus;

final readonly class CancelOrderOutput
{
    public function __construct(public string $orderId, public OrderStatus $status, public bool $cancellationRequested) {}
}
