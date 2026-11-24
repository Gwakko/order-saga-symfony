<?php

declare(strict_types=1);

namespace App\Payment\Application\Command\ChargePayment;

use App\Payment\Domain\PaymentStatus;

final readonly class ChargePaymentOutput
{
    public function __construct(public string $paymentId, public PaymentStatus $status) {}
}
