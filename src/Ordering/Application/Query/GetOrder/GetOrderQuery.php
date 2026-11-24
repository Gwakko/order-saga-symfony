<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\GetOrder;

final readonly class GetOrderQuery
{
    public function __construct(public string $orderId, public string $customerId) {}
}
