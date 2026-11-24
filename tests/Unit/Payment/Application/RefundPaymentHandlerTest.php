<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Application;

use App\Payment\Application\Command\RefundPayment\RefundPaymentCommand;
use App\Payment\Application\Exception\PaymentGatewayUnavailable;
use App\Payment\Application\Service\RefundOutcome;
use App\Payment\Domain\PaymentStatus;
use App\Tests\Unit\Ordering\Domain\OrderFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * T-U-37: the RefundPayment compensation by payment status (EC-45, EC-49).
 */
#[Group('T-U-37')]
final class RefundPaymentHandlerTest extends TestCase
{
    private PaymentWorld $world;

    protected function setUp(): void
    {
        $this->world = new PaymentWorld();
    }

    private function refund(): RefundOutcome
    {
        return ($this->world->refundHandler())(new RefundPaymentCommand(OrderFactory::ORDER_ID))->status;
    }

    public function testSucceededIsRefunded(): void
    {
        $this->world->seedPayment(PaymentStatus::Succeeded);

        self::assertSame(RefundOutcome::Refunded, $this->refund());
        self::assertSame(PaymentStatus::Refunded, $this->world->status());
        self::assertSame(1, $this->world->gateway->refundCalls);
        self::assertSame('rf_1', $this->world->payments->findByOrder($this->world->orderId())?->gatewayRefundId);
    }

    public function testRefundPendingRepeatsTheRefundCall(): void
    {
        $this->world->seedPayment(PaymentStatus::RefundPending);

        self::assertSame(RefundOutcome::Refunded, $this->refund());
        self::assertSame(PaymentStatus::Refunded, $this->world->status());
        self::assertSame(1, $this->world->gateway->refundCalls);
    }

    public function testUnknownWithFoundChargeIsRefunded(): void
    {
        $this->world->seedPayment(PaymentStatus::Unknown);
        $this->world->gatewayKnowsSuccessfulCharge();

        self::assertSame(RefundOutcome::Refunded, $this->refund());
        self::assertSame(PaymentStatus::Refunded, $this->world->status());
        self::assertSame('ch_late', $this->world->payments->findByOrder($this->world->orderId())?->gatewayChargeId);
        self::assertSame(1, $this->world->gateway->findCalls);
    }

    /**
     * EC-49: no trace of a charge at the gateway: abandon, never refund.
     */
    public function testUnknownWithoutChargeIsAbandonedWithoutRefund(): void
    {
        $this->world->seedPayment(PaymentStatus::Unknown);

        self::assertSame(RefundOutcome::NothingToRefund, $this->refund());
        self::assertSame(PaymentStatus::Abandoned, $this->world->status());
        self::assertSame(0, $this->world->gateway->refundCalls);
        self::assertSame(1, $this->world->gateway->findCalls);
    }

    public function testPendingWithoutChargeIsAbandoned(): void
    {
        $this->world->seedPayment(PaymentStatus::Pending);

        self::assertSame(RefundOutcome::NothingToRefund, $this->refund());
        self::assertSame(PaymentStatus::Abandoned, $this->world->status());
        self::assertSame(0, $this->world->gateway->refundCalls);
    }

    public function testUnknownWithFoundDeclinedChargeNeedsNoRefund(): void
    {
        $this->world->seedPayment(PaymentStatus::Unknown);
        $this->world->gatewayKnowsDeclinedCharge();

        self::assertSame(RefundOutcome::NothingToRefund, $this->refund());
        self::assertSame(PaymentStatus::Declined, $this->world->status());
        self::assertSame(0, $this->world->gateway->refundCalls);
    }

    public function testDeclinedAndAbandonedHaveNothingToRefund(): void
    {
        foreach ([PaymentStatus::Declined, PaymentStatus::Abandoned] as $status) {
            $world = new PaymentWorld();
            $world->seedPayment($status);

            self::assertSame(RefundOutcome::NothingToRefund, ($world->refundHandler())(new RefundPaymentCommand(OrderFactory::ORDER_ID))->status);
            self::assertSame($status, $world->status());
            self::assertSame(0, $world->gateway->refundCalls);
            self::assertSame(0, $world->gateway->findCalls);
        }
    }

    public function testNoPaymentMeansNothingToRefund(): void
    {
        self::assertSame(RefundOutcome::NothingToRefund, $this->refund());
        self::assertSame(0, $this->world->gateway->refundCalls);
    }

    public function testAlreadyRefundedIsAnIdempotentSuccess(): void
    {
        $this->world->seedPayment(PaymentStatus::Refunded);

        self::assertSame(RefundOutcome::Refunded, $this->refund());
        self::assertSame(0, $this->world->gateway->refundCalls);
    }

    /**
     * EC-45: the gateway fails twice, the third try succeeds, and the gateway booked exactly one refund.
     */
    public function testTransientGatewayFailuresAreRetriedIntoOneRefund(): void
    {
        $this->world->seedPayment(PaymentStatus::Succeeded);
        $this->world->gateway->failRefunds(2);

        foreach ([1, 2] as $attempt) {
            try {
                $this->refund();
                self::fail('Expected PaymentGatewayUnavailable on attempt ' . $attempt);
            } catch (PaymentGatewayUnavailable) {
            }

            self::assertSame(PaymentStatus::RefundPending, $this->world->status());
        }

        self::assertSame(RefundOutcome::Refunded, $this->refund());
        self::assertSame(3, $this->world->gateway->refundCalls);
        self::assertSame(1, $this->world->gateway->bookedRefunds());
        self::assertSame(PaymentStatus::Refunded, $this->world->status());
    }
}
