<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Schedule;

/**
 * Scheduler message: run the payment reconciliation once.
 */
final readonly class ReconcilePaymentsTick
{
    public function __construct(public int $olderThanSeconds = 300, public int $limit = 200) {}
}
