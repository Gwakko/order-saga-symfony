<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Application;

use App\Payment\Application\Command\ChargePayment\ChargePaymentCommand;
use App\Payment\Application\Exception\AmountMismatch;
use App\Payment\Application\Exception\CircuitOpen;
use App\Payment\Application\Exception\PaymentDeclined;
use App\Payment\Application\Exception\PaymentGatewayUnavailable;
use App\Payment\Application\Exception\PaymentOutcomeUnknown;
use App\Payment\Application\Exception\ReservationNotActive;
use App\Payment\Domain\Event\PaymentCapturedWithMismatch;
use App\Payment\Domain\MismatchKind;
use App\Payment\Domain\PaymentStatus;
use App\Shared\Application\Exception\RetryableFailure;
use App\Shared\Domain\Currency;
use App\Shared\Domain\DomainErrorCode;
use App\Shared\Domain\Exception\CurrencyMismatch;
use App\Shared\Domain\Money;
use App\Tests\Support\Fake\GatewayScenario;
use App\Tests\Unit\Ordering\Domain\OrderFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class ChargePaymentHandlerTest extends TestCase
{
    private PaymentWorld $world;

    protected function setUp(): void
    {
        $this->world = new PaymentWorld();
    }

    private static function isRetryable(\Throwable $e): bool
    {
        return (new \ReflectionClass($e))->implementsInterface(RetryableFailure::class);
    }

    private function charge(): \App\Payment\Application\Command\ChargePayment\ChargePaymentOutput
    {
        return ($this->world->chargeHandler())(new ChargePaymentCommand(OrderFactory::ORDER_ID));
    }

    public function testChargesOnceAndStoresSuccessWithEvent(): void
    {
        $output = $this->charge();

        self::assertSame($this->world->firstPaymentId(), $output->paymentId);
        self::assertSame(PaymentStatus::Succeeded, $output->status);
        self::assertSame(1, $this->world->gateway->chargeCalls);
        self::assertSame(PaymentStatus::Succeeded, $this->world->status());
        self::assertCount(1, $this->world->payments->events);
    }

    /**
     * EC-10: a stored success is returned without touching the gateway.
     */
    #[Group('T-U-30')]
    public function testSucceededPaymentShortCircuits(): void
    {
        $this->world->seedPayment(PaymentStatus::Succeeded);

        $output = $this->charge();

        self::assertSame(PaymentStatus::Succeeded, $output->status);
        self::assertSame(0, $this->world->gateway->chargeCalls);
        self::assertSame(0, $this->world->gateway->findCalls);
    }

    public function testRepeatAfterSuccessDoesNotChargeAgain(): void
    {
        $this->charge();
        $this->charge();

        self::assertSame(1, $this->world->gateway->chargeCalls);
    }

    /**
     * EC-17: the reservation ends in less than the guard time, the gateway is not called.
     */
    #[Group('T-U-31')]
    public function testExpiredReservationSkipsGateway(): void
    {
        // Reservation expires at 10:15:00; at 10:14:30 only 30 s are left, the guard asks for 60.
        $this->world->clock->modify('2026-05-01T10:14:30Z');

        try {
            $this->charge();
            self::fail('Expected ReservationNotActive');
        } catch (ReservationNotActive $e) {
            self::assertSame(DomainErrorCode::ReservationExpired, $e->code());
            self::assertFalse(self::isRetryable($e));
        }

        self::assertSame(0, $this->world->gateway->chargeCalls);
        self::assertSame(0, $this->world->gateway->findCalls);
    }

    public function testGuardBoundaryExactlyAtGuardTimeStillCharges(): void
    {
        // Exactly 60 s left: "ends earlier than in 60 s" is false.
        $this->world->clock->modify('2026-05-01T10:14:00Z');

        self::assertSame(PaymentStatus::Succeeded, $this->charge()->status);
    }

    public function testReservationAlreadyGoneSkipsGateway(): void
    {
        $this->world->clock->modify('2026-05-01T10:30:00Z');

        $this->expectException(ReservationNotActive::class);

        $this->charge();
    }

    /**
     * EC-24, EC-27: a timeout leaves the payment `Unknown`; the next attempt asks the gateway first and finds the success.
     */
    #[Group('T-U-33')]
    public function testTimeoutMarksUnknownThenChecksStatus(): void
    {
        $this->world->gateway->script(GatewayScenario::TimeoutAfterCharge);

        try {
            $this->charge();
            self::fail('Expected PaymentOutcomeUnknown');
        } catch (PaymentOutcomeUnknown $e) {
            self::assertSame(DomainErrorCode::PaymentOutcomeUnknown, $e->code());
            self::assertTrue(self::isRetryable($e));
        }

        self::assertSame(PaymentStatus::Unknown, $this->world->status());
        self::assertSame(1, $this->world->payments->findByOrder($this->world->orderId())?->attempts);

        $output = $this->charge();

        self::assertSame(PaymentStatus::Succeeded, $output->status);
        self::assertSame(1, $this->world->gateway->chargeCalls, 'the second attempt must not charge again');
        self::assertSame(1, $this->world->gateway->findCalls);
    }

    public function testTimeoutBeforeChargeIsRetriedAfterLookupFindsNothing(): void
    {
        $this->world->gateway->script(GatewayScenario::TimeoutBeforeCharge);

        try {
            $this->charge();
            self::fail('Expected PaymentOutcomeUnknown');
        } catch (PaymentOutcomeUnknown) {
        }

        $output = $this->charge();

        self::assertSame(PaymentStatus::Succeeded, $output->status);
        self::assertSame(1, $this->world->gateway->findCalls);
        self::assertSame(2, $this->world->gateway->chargeCalls);
    }

    public function testRepeatedTimeoutKeepsUnknownAndCountsAttempts(): void
    {
        $this->world->gateway->script(GatewayScenario::TimeoutBeforeCharge, GatewayScenario::TimeoutBeforeCharge);

        foreach ([1, 2] as $attempt) {
            try {
                $this->charge();
                self::fail('Expected PaymentOutcomeUnknown');
            } catch (PaymentOutcomeUnknown) {
            }

            self::assertSame($attempt, $this->world->payments->findByOrder($this->world->orderId())?->attempts);
        }

        self::assertSame(PaymentStatus::Unknown, $this->world->status());
    }

    public function testFoundDeclinedChargeAfterTimeoutFailsTheOrder(): void
    {
        $this->world->seedPayment(PaymentStatus::Unknown);
        $this->world->gatewayKnowsDeclinedCharge();

        $this->expectException(PaymentDeclined::class);

        try {
            $this->charge();
        } finally {
            self::assertSame(PaymentStatus::Declined, $this->world->status());
            self::assertSame(0, $this->world->gateway->chargeCalls);
        }
    }

    /**
     * EC-32: the breaker rejected the call; nothing was sent, so the payment stays `Pending` (not `Unknown`).
     */
    #[Group('T-U-34')]
    public function testCircuitOpenKeepsPending(): void
    {
        $this->world->gateway->script(GatewayScenario::CircuitOpen);

        try {
            $this->charge();
            self::fail('Expected CircuitOpen');
        } catch (CircuitOpen $e) {
            self::assertTrue(self::isRetryable($e));
        }

        $payment = $this->world->payments->findByOrder($this->world->orderId());
        self::assertSame(PaymentStatus::Pending, $payment?->status);
        self::assertSame(0, $payment->attempts);
    }

    public function testGatewayUnavailableKeepsPendingAndIsRetryable(): void
    {
        $this->world->gateway->script(GatewayScenario::Unavailable);

        $this->expectException(PaymentGatewayUnavailable::class);

        try {
            $this->charge();
        } finally {
            self::assertSame(PaymentStatus::Pending, $this->world->status());
        }
    }

    public function testBankDeclineIsStoredAndNotRetryable(): void
    {
        $this->world->gateway->script(GatewayScenario::Decline);

        try {
            $this->charge();
            self::fail('Expected PaymentDeclined');
        } catch (PaymentDeclined $e) {
            self::assertSame(DomainErrorCode::PaymentDeclined, $e->code());
            self::assertSame('insufficient_funds', $e->reason);
            self::assertFalse(self::isRetryable($e));
        }

        self::assertSame(PaymentStatus::Declined, $this->world->status());

        // A repeated activity does not go to the gateway again.
        try {
            $this->charge();
            self::fail('Expected PaymentDeclined');
        } catch (PaymentDeclined) {
        }

        self::assertSame(1, $this->world->gateway->chargeCalls);
    }

    public function testAbandonedPaymentIsNeverCharged(): void
    {
        $this->world->seedPayment(PaymentStatus::Abandoned);

        try {
            $this->charge();
            self::fail('Expected PaymentDeclined');
        } catch (PaymentDeclined $e) {
            self::assertSame(PaymentDeclined::ABANDONED, $e->reason);
        }

        self::assertSame(0, $this->world->gateway->chargeCalls);
    }

    /**
     * EC-36: the gateway captured USD for an EUR payment: no success, `RefundPending`, charge id kept for the refund.
     */
    #[Group('T-U-35')]
    public function testCurrencyMismatchInGatewayResponse(): void
    {
        $this->world->gateway->script(GatewayScenario::WrongCurrency);

        try {
            $this->charge();
            self::fail('Expected CurrencyMismatch');
        } catch (CurrencyMismatch $e) {
            self::assertSame(DomainErrorCode::CurrencyMismatch, $e->code());
            self::assertFalse(self::isRetryable($e));
        }

        $payment = $this->world->payments->findByOrder($this->world->orderId());
        self::assertSame(PaymentStatus::RefundPending, $payment?->status);
        self::assertNotNull($payment->gatewayChargeId);
        self::assertTrue($payment->amount->equals(Money::positive(1999, Currency::EUR)), 'the requested amount is kept (INV-18)');

        $event = $this->world->payments->events[0] ?? null;
        self::assertInstanceOf(PaymentCapturedWithMismatch::class, $event);
        self::assertSame(MismatchKind::Currency, $event->reason->kind);
        self::assertSame(Currency::USD, $event->reason->charged->currency);

        // The saga compensates: the charge is refunded by charge id and the order would end as Failed(CurrencyMismatch).
        $refund = ($this->world->refundHandler())(new \App\Payment\Application\Command\RefundPayment\RefundPaymentCommand(OrderFactory::ORDER_ID));
        self::assertSame(\App\Payment\Application\Service\RefundOutcome::Refunded, $refund->status);
        self::assertSame(PaymentStatus::Refunded, $this->world->status());
        self::assertSame(1, $this->world->gateway->bookedRefunds());
    }

    /**
     * EC-37: the gateway captured another amount than requested.
     */
    #[Group('T-U-36')]
    public function testAmountMismatchInGatewayResponse(): void
    {
        $this->world->gateway->script(GatewayScenario::WrongAmount);

        try {
            $this->charge();
            self::fail('Expected AmountMismatch');
        } catch (AmountMismatch $e) {
            self::assertSame(DomainErrorCode::AmountMismatch, $e->code());
            self::assertSame(1999, $e->expected->amount);
            self::assertSame(2000, $e->charged->amount);
            self::assertFalse(self::isRetryable($e));
        }

        $payment = $this->world->payments->findByOrder($this->world->orderId());
        self::assertSame(PaymentStatus::RefundPending, $payment?->status);
        self::assertSame(1999, $payment->amount->amount);

        $event = $this->world->payments->events[0] ?? null;
        self::assertInstanceOf(PaymentCapturedWithMismatch::class, $event);
        self::assertSame(MismatchKind::Amount, $event->reason->kind);
        self::assertSame(2000, $event->reason->charged->amount);

        $refund = ($this->world->refundHandler())(new \App\Payment\Application\Command\RefundPayment\RefundPaymentCommand(OrderFactory::ORDER_ID));
        self::assertSame(\App\Payment\Application\Service\RefundOutcome::Refunded, $refund->status);
        self::assertSame(1, $this->world->gateway->bookedRefunds());
    }

    public function testRepeatAfterMismatchFailsTheSameWayWithoutTheGateway(): void
    {
        $this->world->gateway->script(GatewayScenario::WrongCurrency);

        try {
            $this->charge();
            self::fail('Expected CurrencyMismatch');
        } catch (CurrencyMismatch) {
        }

        try {
            $this->charge();
            self::fail('Expected CurrencyMismatch on the repeat');
        } catch (CurrencyMismatch $e) {
            self::assertSame(Currency::USD, $e->right);
        }

        self::assertSame(1, $this->world->gateway->chargeCalls);
    }

    /**
     * EC-81: the command carries only the order id; the amount is the stored order's price.
     */
    #[Group('T-U-39')]
    public function testAmountComesFromOrder(): void
    {
        $constructor = (new \ReflectionClass(ChargePaymentCommand::class))->getConstructor();
        self::assertNotNull($constructor);
        self::assertSame(['orderId'], array_map(static fn(\ReflectionParameter $p): string => $p->getName(), $constructor->getParameters()));

        $this->charge();

        $order = $this->world->orders->get($this->world->orderId());
        self::assertNotNull($this->world->gateway->lastChargeAmount);
        self::assertTrue($this->world->gateway->lastChargeAmount->equals($order->price));
        self::assertTrue($this->world->payments->findByOrder($this->world->orderId())?->amount->equals($order->price) ?? false);
    }
}
