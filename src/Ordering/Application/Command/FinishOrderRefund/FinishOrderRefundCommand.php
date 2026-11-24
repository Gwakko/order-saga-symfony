<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\FinishOrderRefund;

final readonly class FinishOrderRefundCommand
{
    public function __construct(public string $orderId) {}
}
