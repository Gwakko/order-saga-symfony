<?php

declare(strict_types=1);

namespace App\Payment\Application\Service;

use App\Payment\Application\Port\ChargeResult;
use App\Payment\Application\Port\GatewayChargeStatus;
use App\Payment\Application\Port\PaymentRepository;
use App\Payment\Domain\MismatchKind;
use App\Payment\Domain\MismatchReason;
use App\Payment\Domain\Payment;
use App\Shared\Application\Port\TransactionManager;
use App\Shared\Domain\Exception\CurrencyMismatch;
use Psr\Clock\ClockInterface;

/**
 * Applies a gateway answer to a payment and saves it. A confirmed charge in another currency or amount than
 * requested is not a success: the payment goes to `RefundPending` by charge id (EC-36, EC-37, INV-18).
 */
final readonly class ChargeRecorder
{
    public function __construct(
        private PaymentRepository $payments,
        private TransactionManager $transactions,
        private ClockInterface $clock,
    ) {}

    /**
     * @return Payment the saved payment: `Succeeded`, `Declined` or `RefundPending`
     */
    public function record(Payment $payment, ChargeResult $result): Payment
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        $updated = match ($result->status) {
            GatewayChargeStatus::Declined => $payment->markDeclined($result->declineReason ?? 'declined', $now),
            GatewayChargeStatus::Succeeded => $this->recordSuccess($payment, $result, $now),
        };

        $this->transactions->transactional(fn() => $this->payments->save($updated));

        return $updated;
    }

    private function recordSuccess(Payment $payment, ChargeResult $result, \DateTimeImmutable $now): Payment
    {
        if ($result->amount->currency === $payment->amount->currency && !$result->amount->equals($payment->amount)) {
            return $payment->markCapturedWithMismatch($result->chargeId, new MismatchReason(MismatchKind::Amount, $result->amount), $now);
        }

        try {
            return $payment->markSucceeded($result->chargeId, $result->amount->currency, $now);
        } catch (CurrencyMismatch) {
            return $payment->markCapturedWithMismatch($result->chargeId, new MismatchReason(MismatchKind::Currency, $result->amount), $now);
        }
    }
}
