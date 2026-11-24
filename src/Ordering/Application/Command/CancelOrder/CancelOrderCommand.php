<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\CancelOrder;

final readonly class CancelOrderCommand
{
    public function __construct(public string $orderId, public string $customerId) {}
}
