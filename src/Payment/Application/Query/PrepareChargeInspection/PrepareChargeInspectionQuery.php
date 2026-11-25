<?php

declare(strict_types=1);

namespace App\Payment\Application\Query\PrepareChargeInspection;

final readonly class PrepareChargeInspectionQuery
{
    public function __construct(public string $orderId) {}
}
