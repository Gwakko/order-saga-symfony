<?php

declare(strict_types=1);

namespace App\Payment\Application\Service;

enum RefundOutcome: string
{
    case Refunded = 'Refunded';
    case NothingToRefund = 'NothingToRefund';
}
