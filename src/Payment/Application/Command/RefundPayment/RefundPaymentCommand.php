<?php

declare(strict_types=1);

namespace App\Payment\Application\Command\RefundPayment;

final readonly class RefundPaymentCommand
{
    public function __construct(public string $orderId) {}
}
