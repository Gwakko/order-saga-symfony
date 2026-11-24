<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Activity;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface]
interface PaymentActivities
{
    /** @return string the payment id (a payment that did not succeed raises instead) */
    #[ActivityMethod(name: 'ChargePayment')]
    public function chargePayment(string $orderId): string;

    /** @return string the `RefundOutcome` value */
    #[ActivityMethod(name: 'RefundPayment')]
    public function refundPayment(string $orderId): string;
}
