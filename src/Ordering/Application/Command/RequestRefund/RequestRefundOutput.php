<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\RequestRefund;

use App\Ordering\Domain\OrderStatus;

final readonly class RequestRefundOutput
{
    public function __construct(public string $orderId, public OrderStatus $status) {}
}
