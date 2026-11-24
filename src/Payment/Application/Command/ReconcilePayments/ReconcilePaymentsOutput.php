<?php

declare(strict_types=1);

namespace App\Payment\Application\Command\ReconcilePayments;

final readonly class ReconcilePaymentsOutput
{
    public function __construct(public int $checked, public int $succeededLate, public int $refunded) {}
}
