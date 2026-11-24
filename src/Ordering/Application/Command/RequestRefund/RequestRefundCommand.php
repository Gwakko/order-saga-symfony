<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\RequestRefund;

final readonly class RequestRefundCommand
{
    public function __construct(public string $orderId, public string $customerId) {}
}
