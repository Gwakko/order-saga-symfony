<?php

declare(strict_types=1);

namespace App\Payment\Application\Port;

use App\Shared\Domain\Money;

final readonly class RefundResult
{
    public function __construct(public string $refundId, public Money $amount) {}
}
