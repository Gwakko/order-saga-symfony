<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Application;

use App\Inventory\Domain\KeyReservation;
use App\Payment\Application\Command\ChargePayment\ChargePaymentHandler;
use App\Payment\Application\Command\RefundPayment\RefundPaymentHandler;
use App\Payment\Application\Port\ChargeResult;
use App\Payment\Application\Port\GatewayChargeStatus;
use App\Payment\Application\Service\ChargeRecorder;
use App\Payment\Application\Service\PaymentRefunder;
use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\PaymentId;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Identifier\ProductKeyId;
use App\Shared\Domain\Identifier\ReservationId;
use App\Shared\Domain\Money;
use App\Tests\Support\Fake\FakeOrderOutcomeReader;
use App\Tests\Support\Fake\FakeOrderPriceReader;
use App\Tests\Support\Fake\FakePaymentGateway;
use App\Tests\Support\Fake\FakeReservationStatusReader;
use App\Tests\Support\Fake\InMemoryOrderRepository;
use App\Tests\Support\Fake\InMemoryPaymentRepository;
use App\Tests\Support\Fake\InMemoryReservationRepository;
use App\Tests\Support\Fake\NoopTransactionManager;
use App\Tests\Support\Fake\SequenceIdGenerator;
use App\Tests\Unit\Ordering\Domain\OrderFactory;
use Symfony\Component\Clock\MockClock;

/**
 * Wires the Payment application handlers to in-memory fakes. One order (`OrderFactory::ORDER_ID`, 1999 EUR) with an
 * active reservation (created at t0, 900 s TTL). The clock starts at t0 + 60 s.
 */
final class PaymentWorld
{
    public readonly InMemoryOrderRepository $orders;
    public readonly InMemoryPaymentRepository $payments;
    public readonly InMemoryReservationRepository $reservations;
    public readonly FakePaymentGateway $gateway;
    public readonly SequenceIdGenerator $ids;
    public readonly NoopTransactionManager $transactions;
    public readonly MockClock $clock;
    public readonly FakeOrderOutcomeReader $outcomes;

    public function __construct()
    {
        $this->orders = new InMemoryOrderRepository();
        $this->orders->seed(OrderFactory::keyReserved());
        $this->payments = new InMemoryPaymentRepository();
        $this->reservations = new InMemoryReservationRepository();
        $this->reservations->seed(KeyReservation::create(
            ReservationId::fromString(OrderFactory::RESERVATION_ID),
            OrderId::fromString(OrderFactory::ORDER_ID),
            ProductKeyId::fromString(OrderFactory::KEY_ID),
            ProductId::fromString(OrderFactory::PRODUCT_ID),
            OrderFactory::t0(),
            900,
        ));
        $this->gateway = new FakePaymentGateway();
        $this->ids = new SequenceIdGenerator();
        $this->transactions = new NoopTransactionManager();
        $this->clock = new MockClock('2026-05-01T10:01:00Z');
        $this->outcomes = new FakeOrderOutcomeReader($this->orders);
    }

    public function orderId(): OrderId
    {
        return OrderId::fromString(OrderFactory::ORDER_ID);
    }

    public function chargeHandler(): ChargePaymentHandler
    {
        return new ChargePaymentHandler(
            $this->payments,
            new FakeOrderPriceReader($this->orders),
            new FakeReservationStatusReader($this->reservations),
            $this->gateway,
            $this->recorder(),
            $this->transactions,
            $this->ids,
            $this->clock,
        );
    }

    public function refundHandler(): RefundPaymentHandler
    {
        return new RefundPaymentHandler($this->payments, $this->refunder());
    }

    public function recorder(): ChargeRecorder
    {
        return new ChargeRecorder($this->payments, $this->transactions, $this->clock);
    }

    public function refunder(): PaymentRefunder
    {
        return new PaymentRefunder($this->payments, $this->gateway, $this->recorder(), $this->transactions, $this->clock);
    }

    /**
     * The payment id the handler will take for the first payment it creates.
     */
    public function firstPaymentId(): string
    {
        return $this->ids->peek(1);
    }

    /**
     * Stores a payment of the order in the given status, reached through real transitions (charge id `ch_1`,
     * refund id `rf_1`). Its updated-at is `$at` seconds after t0.
     */
    public function seedPayment(PaymentStatus $status, int $at = 1): Payment
    {
        $t = static fn(int $s): \DateTimeImmutable => OrderFactory::later($s);
        $pending = Payment::create(PaymentId::fromString($this->ids->peek(1)), $this->orderId(), Money::positive(1999, Currency::EUR), $t(0));

        $payment = match ($status) {
            PaymentStatus::Pending => $pending,
            PaymentStatus::Unknown => $pending->markUnknown('timeout', $t($at)),
            PaymentStatus::Succeeded => $pending->markSucceeded('ch_1', Currency::EUR, $t($at)),
            PaymentStatus::Declined => $pending->markDeclined('insufficient_funds', $t($at)),
            PaymentStatus::Abandoned => $pending->abandon($t($at)),
            PaymentStatus::RefundPending => $pending->markSucceeded('ch_1', Currency::EUR, $t($at))->requestRefund($t($at + 1)),
            PaymentStatus::Refunded => $pending->markSucceeded('ch_1', Currency::EUR, $t($at))->requestRefund($t($at + 1))->markRefunded('rf_1', $t($at + 2)),
        };

        $this->payments->seed($payment);

        return $payment;
    }

    public function gatewayKnowsSuccessfulCharge(?Money $amount = null): void
    {
        $this->gateway->seedCharge($this->ids->peek(1), new ChargeResult('ch_late', GatewayChargeStatus::Succeeded, $amount ?? Money::positive(1999, Currency::EUR)));
    }

    public function gatewayKnowsDeclinedCharge(): void
    {
        $this->gateway->seedCharge($this->ids->peek(1), new ChargeResult('ch_declined', GatewayChargeStatus::Declined, Money::positive(1999, Currency::EUR), 'insufficient_funds'));
    }

    public function status(): ?PaymentStatus
    {
        return $this->payments->findByOrder($this->orderId())?->status;
    }
}
