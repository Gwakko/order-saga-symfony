<?php

declare(strict_types=1);

namespace App\Payment\Application\Port;

use App\Payment\Application\Exception\CircuitOpen;
use App\Payment\Application\Exception\PaymentGatewayUnavailable;
use App\Payment\Application\Exception\PaymentOutcomeUnknown;
use App\Shared\Domain\Money;

interface PaymentGateway
{
    /**
     * `$idempotencyKey` is the payment id; the gateway deduplicates by it.
     *
     * @throws PaymentOutcomeUnknown timeout, dropped connection or unreadable answer: the charge may exist
     * @throws PaymentGatewayUnavailable the gateway answered with a server error
     * @throws CircuitOpen the call was not made
     */
    public function charge(string $idempotencyKey, Money $amount): ChargeResult;

    /**
     * Asks whether a charge with this key exists; null when the gateway does not know it.
     *
     * @throws PaymentGatewayUnavailable
     * @throws CircuitOpen
     */
    public function findCharge(string $idempotencyKey): ?ChargeResult;

    /**
     * Full refund of the charge (`refund-{paymentId}` is the key).
     *
     * @throws PaymentGatewayUnavailable
     * @throws CircuitOpen
     */
    public function refund(string $idempotencyKey, string $chargeId, Money $amount): RefundResult;
}
