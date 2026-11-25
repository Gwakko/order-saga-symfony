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

    /**
     * Read-only; decides whether the saga asks the gateway about a charge it gave up on (docs/spec/09_go_worker.md section 2.3).
     *
     * @return array{inspect: bool, paymentId: ?string, amount: ?int, currency: ?string}
     */
    #[ActivityMethod(name: 'PrepareChargeInspection')]
    public function prepareChargeInspection(string $orderId): array;
}
