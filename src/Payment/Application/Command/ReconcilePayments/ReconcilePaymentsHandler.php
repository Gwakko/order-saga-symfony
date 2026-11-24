<?php

declare(strict_types=1);

namespace App\Payment\Application\Command\ReconcilePayments;

use App\Payment\Application\Port\GatewayChargeStatus;
use App\Payment\Application\Port\OrderOutcomeReader;
use App\Payment\Application\Port\PaymentGateway;
use App\Payment\Application\Port\PaymentRepository;
use App\Payment\Application\Service\ChargeRecorder;
use App\Payment\Application\Service\PaymentRefunder;
use App\Payment\Application\Service\RefundOutcome;
use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentStatus;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Exception\RetryableFailure;
use Psr\Clock\ClockInterface;

/**
 * Safety net for payments whose outcome nobody confirmed (`Unknown`, `Abandoned`) or whose refund did not finish
 * (`RefundPending`) for longer than the threshold.
 * Asks the gateway. A charge that exists for an order that ended without a key (`Failed`/`Cancelled`) is an orphan:
 * it is recorded and refunded (EC-28). An `Unknown` payment of an order still in progress is left to its workflow,
 * and a declined answer closes an `Unknown` payment. A gateway or database failure skips that payment for this run.
 */
final readonly class ReconcilePaymentsHandler implements CommandHandler
{
    public function __construct(
        private PaymentRepository $payments,
        private PaymentGateway $gateway,
        private OrderOutcomeReader $orders,
        private ChargeRecorder $recorder,
        private PaymentRefunder $refunder,
        private ClockInterface $clock,
    ) {}

    public function __invoke(ReconcilePaymentsCommand $command): ReconcilePaymentsOutput
    {
        $before = \DateTimeImmutable::createFromInterface($this->clock->now())->modify(sprintf('-%d seconds', $command->olderThanSeconds));
        $checked = 0;
        $succeededLate = 0;
        $refunded = 0;

        foreach ($this->payments->findOpenUpdatedBefore($before, $command->limit) as $payment) {
            try {
                $result = $this->reconcile($payment);
            } catch (RetryableFailure) {
                continue;
            }

            ++$checked;
            $succeededLate += $result['succeededLate'] ? 1 : 0;
            $refunded += $result['refunded'] ? 1 : 0;
        }

        return new ReconcilePaymentsOutput($checked, $succeededLate, $refunded);
    }

    /**
     * @return array{succeededLate: bool, refunded: bool}
     */
    private function reconcile(Payment $payment): array
    {
        $none = ['succeededLate' => false, 'refunded' => false];

        // A refund that stalled (gateway down during the compensation, or an orphan found earlier): finish it.
        if ($payment->status === PaymentStatus::RefundPending) {
            return ['succeededLate' => false, 'refunded' => $this->refunder->refund($payment) === RefundOutcome::Refunded];
        }

        $charge = $this->gateway->findCharge($payment->id->value);

        if ($charge === null) {
            return $none;
        }

        if ($charge->status === GatewayChargeStatus::Declined) {
            if ($payment->status === PaymentStatus::Unknown) {
                $this->recorder->record($payment, $charge);
            }

            return $none;
        }

        if ($payment->status === PaymentStatus::Unknown && !$this->orders->endedWithoutKey($payment->orderId)) {
            return $none;
        }

        $recorded = $this->recorder->record($payment, $charge);

        return [
            'succeededLate' => true,
            'refunded' => $this->refunder->refund($recorded) === RefundOutcome::Refunded,
        ];
    }
}
