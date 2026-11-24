<?php

declare(strict_types=1);

namespace App\Payment\Application\Command\RefundPayment;

use App\Payment\Application\Service\RefundOutcome;

final readonly class RefundPaymentOutput
{
    public function __construct(public RefundOutcome $status) {}
}
