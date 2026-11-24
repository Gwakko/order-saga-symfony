<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\CompleteOrder;

final readonly class CompleteOrderCommand
{
    public function __construct(public string $orderId, public string $keyId) {}
}
