<?php

declare(strict_types=1);

namespace App\Payment\Application\Service;

use App\Payment\Application\Port\PaymentGateway;
use App\Payment\Application\Port\PaymentRepository;
use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentStatus;
use App\Shared\Application\Port\TransactionManager;
use Psr\Clock\ClockInterface;

/**
 * Brings a payment to "nothing is charged any more" (03_saga.md section 4). Used by the `RefundPayment` compensation
 * and by reconciliation. Each state change is saved on its own, so a gateway failure leaves `RefundPending` behind
 * and the repeated call only has to repeat the refund call (idempotent by `refund-{paymentId}`).
 */
final readonly class PaymentRefunder
{
    public function __construct(
        private PaymentRepository $payments,
        private PaymentGateway $gateway,
        private ChargeRecorder $recorder,
        private TransactionManager $transactions,
        private ClockInterface $clock,
    ) {}

    public function refund(Payment $payment): RefundOutcome
    {
        return match ($payment->status) {
            PaymentStatus::Refunded => RefundOutcome::Refunded,
            PaymentStatus::Declined, PaymentStatus::Abandoned => RefundOutcome::NothingToRefund,
            PaymentStatus::RefundPending => $this->refundCharge($payment),
            PaymentStatus::Succeeded => $this->refundCharge($this->save($payment->requestRefund($this->now()))),
            PaymentStatus::Pending, PaymentStatus::Unknown => $this->resolveOpenOutcome($payment),
        };
    }

    /**
     * No answer was seen: ask the gateway whether the charge exists before deciding.
     */
    private function resolveOpenOutcome(Payment $payment): RefundOutcome
    {
        $charge = $this->gateway->findCharge($payment->id->value);

        if ($charge === null) {
            $this->save($payment->abandon($this->now()));

            return RefundOutcome::NothingToRefund;
        }

        $recorded = $this->recorder->record($payment, $charge);

        return match ($recorded->status) {
            PaymentStatus::Succeeded => $this->refundCharge($this->save($recorded->requestRefund($this->now()))),
            PaymentStatus::RefundPending => $this->refundCharge($recorded),
            default => RefundOutcome::NothingToRefund,
        };
    }

    private function refundCharge(Payment $payment): RefundOutcome
    {
        $chargeId = $payment->gatewayChargeId ?? throw new \LogicException('A payment to refund has no charge id.');
        $result = $this->gateway->refund('refund-' . $payment->id->value, $chargeId, $payment->amount);

        $this->save($payment->markRefunded($result->refundId, $this->now()));

        return RefundOutcome::Refunded;
    }

    private function save(Payment $payment): Payment
    {
        $this->transactions->transactional(fn() => $this->payments->save($payment));

        return $payment;
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
