<?php

declare(strict_types=1);

namespace App\Payment\Application\Command\ChargePayment;

use App\Payment\Application\Exception\AmountMismatch;
use App\Payment\Application\Exception\PaymentDeclined;
use App\Payment\Application\Exception\PaymentOutcomeUnknown;
use App\Payment\Application\Exception\ReservationNotActive;
use App\Payment\Application\Port\OrderPriceReader;
use App\Payment\Application\Port\PaymentGateway;
use App\Payment\Application\Port\PaymentRepository;
use App\Payment\Application\Port\ReservationStatusReader;
use App\Payment\Application\Service\ChargeRecorder;
use App\Payment\Domain\MismatchKind;
use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentStatus;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Port\IdGenerator;
use App\Shared\Application\Port\TransactionManager;
use App\Shared\Domain\Exception\CurrencyMismatch;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\PaymentId;
use Psr\Clock\ClockInterface;

/**
 * Charges the order once (02_use_cases.md section 2). Idempotent: a stored success never reaches the gateway (EC-10),
 * an unknown outcome is looked up before charging again (EC-24, EC-27).
 *
 * Outcomes: returns on success; throws PaymentDeclined, CurrencyMismatch, AmountMismatch, ReservationNotActive
 * (non-retryable) or PaymentOutcomeUnknown, PaymentGatewayUnavailable, CircuitOpen (retryable).
 */
final readonly class ChargePaymentHandler implements CommandHandler
{
    public function __construct(
        private PaymentRepository $payments,
        private OrderPriceReader $orderPrices,
        private ReservationStatusReader $reservations,
        private PaymentGateway $gateway,
        private ChargeRecorder $recorder,
        private TransactionManager $transactions,
        private IdGenerator $ids,
        private ClockInterface $clock,
        private int $chargeGuardSeconds = 60,
    ) {}

    public function __invoke(ChargePaymentCommand $command): ChargePaymentOutput
    {
        $orderId = OrderId::fromString($command->orderId);
        $payment = $this->loadOrCreate($orderId);

        return match ($payment->status) {
            PaymentStatus::Succeeded => $this->output($payment),
            PaymentStatus::Declined => throw new PaymentDeclined($orderId, $payment->lastError ?? 'declined'),
            PaymentStatus::Abandoned => throw new PaymentDeclined($orderId, PaymentDeclined::ABANDONED),
            PaymentStatus::RefundPending, PaymentStatus::Refunded => $this->failFromRecordedMismatch($payment),
            PaymentStatus::Pending, PaymentStatus::Unknown => $this->charge($payment),
        };
    }

    private function loadOrCreate(OrderId $orderId): Payment
    {
        $existing = $this->payments->findByOrder($orderId);

        if ($existing !== null) {
            return $existing;
        }

        $price = $this->orderPrices->priceOf($orderId);
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $new = Payment::create(PaymentId::fromString($this->ids->next()), $orderId, $price, $now);

        // The unique index on order_id decides a race between two retries: both continue with the stored row.
        return $this->transactions->transactional(fn(): Payment => $this->payments->insertOrGet($new));
    }

    private function charge(Payment $payment): ChargePaymentOutput
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $this->assertReservationAllowsCharge($payment->orderId, $now);

        if ($payment->status === PaymentStatus::Unknown) {
            $found = $this->gateway->findCharge($payment->id->value);

            if ($found !== null) {
                return $this->conclude($this->recorder->record($payment, $found));
            }
        }

        try {
            $result = $this->gateway->charge($payment->id->value, $payment->amount);
        } catch (PaymentOutcomeUnknown $e) {
            $counted = $this->save($payment->recordAttempt($now));
            $unknown = $counted->markUnknown($e->getMessage(), $now);

            // Already `Unknown` (a retry that timed out again): markUnknown is a no-op and writes nothing.
            if ($unknown !== $counted) {
                $this->save($unknown);
            }

            throw $e;
        }

        // CircuitOpen and PaymentGatewayUnavailable propagate untouched: no call completed, the payment keeps its status (EC-32).
        return $this->conclude($this->recorder->record($this->save($payment->recordAttempt($now)), $result));
    }

    /**
     * One transition per save: the repository checks `stored version + 1 = version`.
     */
    private function save(Payment $payment): Payment
    {
        $this->transactions->transactional(fn() => $this->payments->save($payment));

        return $payment;
    }

    private function assertReservationAllowsCharge(OrderId $orderId, \DateTimeImmutable $now): void
    {
        $until = $this->reservations->activeUntil($orderId);

        if ($until === null || $until < $now->modify(sprintf('+%d seconds', $this->chargeGuardSeconds))) {
            throw new ReservationNotActive($orderId);
        }
    }

    private function conclude(Payment $payment): ChargePaymentOutput
    {
        return match ($payment->status) {
            PaymentStatus::Declined => throw new PaymentDeclined($payment->orderId, $payment->lastError ?? 'declined'),
            PaymentStatus::RefundPending => $this->failFromRecordedMismatch($payment),
            default => $this->output($payment),
        };
    }

    private function failFromRecordedMismatch(Payment $payment): never
    {
        $mismatch = $payment->capturedMismatch();

        throw match ($mismatch?->kind) {
            MismatchKind::Currency => new CurrencyMismatch($payment->amount->currency, $mismatch->charged->currency),
            MismatchKind::Amount => new AmountMismatch($payment->amount, $mismatch->charged),
            null => new PaymentDeclined($payment->orderId, 'refund_in_progress'),
        };
    }

    private function output(Payment $payment): ChargePaymentOutput
    {
        return new ChargePaymentOutput($payment->id->value, $payment->status);
    }
}
