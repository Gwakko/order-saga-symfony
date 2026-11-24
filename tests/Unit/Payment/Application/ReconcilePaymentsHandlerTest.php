<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Application;

use App\Ordering\Domain\Order;
use App\Payment\Application\Command\ReconcilePayments\ReconcilePaymentsCommand;
use App\Payment\Application\Command\ReconcilePayments\ReconcilePaymentsHandler;
use App\Payment\Application\Command\ReconcilePayments\ReconcilePaymentsOutput;
use App\Payment\Domain\Event\OrphanChargeDetected;
use App\Payment\Domain\PaymentStatus;
use App\Shared\Application\Exception\InvalidInput;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tests\Unit\Ordering\Domain\OrderFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * ReconcilePayments (EC-28): late successes of orders that ended without a key are refunded.
 */
#[Group('M3.4')]
final class ReconcilePaymentsHandlerTest extends TestCase
{
    private PaymentWorld $world;

    protected function setUp(): void
    {
        $this->world = new PaymentWorld();
        // Payments below were last touched a few seconds after t0; the run happens ten minutes later.
        $this->world->clock->modify('2026-05-01T10:10:00Z');
    }

    private function reconcile(int $olderThan = 300, int $limit = 100): ReconcilePaymentsOutput
    {
        $handler = new ReconcilePaymentsHandler(
            $this->world->payments,
            $this->world->gateway,
            $this->world->outcomes,
            $this->world->recorder(),
            $this->world->refunder(),
            $this->world->clock,
        );

        return $handler(new ReconcilePaymentsCommand($olderThan, $limit));
    }

    private function orderEnded(Order $order): void
    {
        $this->world->orders->seed($order);
    }

    /**
     * EC-28: the compensation abandoned the payment, the bank charged anyway; the orphan is refunded.
     */
    public function testLateSuccessOfAnAbandonedPaymentIsRefunded(): void
    {
        $this->orderEnded(OrderFactory::compensating()->finishCompensation(OrderFactory::later(60)));
        $this->world->seedPayment(PaymentStatus::Abandoned);
        $this->world->gatewayKnowsSuccessfulCharge();

        $result = $this->reconcile();

        self::assertSame(1, $result->checked);
        self::assertSame(1, $result->succeededLate);
        self::assertSame(1, $result->refunded);
        self::assertSame(PaymentStatus::Refunded, $this->world->status());
        self::assertSame(1, $this->world->gateway->bookedRefunds());
        self::assertTrue(array_filter($this->world->payments->events, static fn($e): bool => $e instanceof OrphanChargeDetected) !== []);
    }

    public function testLateSuccessOfAnUnknownPaymentOfAFailedOrderIsRefunded(): void
    {
        $this->orderEnded(OrderFactory::failed());
        $this->world->seedPayment(PaymentStatus::Unknown);
        $this->world->gatewayKnowsSuccessfulCharge();

        $result = $this->reconcile();

        self::assertSame(1, $result->succeededLate);
        self::assertSame(1, $result->refunded);
        self::assertSame(PaymentStatus::Refunded, $this->world->status());
    }

    public function testUnknownPaymentOfAnOrderInProgressIsLeftToItsWorkflow(): void
    {
        $this->world->seedPayment(PaymentStatus::Unknown);
        $this->world->gatewayKnowsSuccessfulCharge();

        $result = $this->reconcile();

        self::assertSame(1, $result->checked);
        self::assertSame(0, $result->succeededLate);
        self::assertSame(0, $result->refunded);
        self::assertSame(PaymentStatus::Unknown, $this->world->status());
        self::assertSame(0, $this->world->gateway->refundCalls);
    }

    public function testLateSuccessInAnotherAmountIsRefundedByChargeId(): void
    {
        $this->orderEnded(OrderFactory::failed());
        $this->world->seedPayment(PaymentStatus::Abandoned);
        $this->world->gatewayKnowsSuccessfulCharge(Money::positive(2099, Currency::EUR));

        $result = $this->reconcile();

        self::assertSame(1, $result->refunded);
        self::assertSame(PaymentStatus::Refunded, $this->world->status());
    }

    public function testNothingAtTheGatewayChangesNothing(): void
    {
        $this->orderEnded(OrderFactory::failed());
        $this->world->seedPayment(PaymentStatus::Abandoned);

        $result = $this->reconcile();

        self::assertSame(1, $result->checked);
        self::assertSame(0, $result->succeededLate);
        self::assertSame(PaymentStatus::Abandoned, $this->world->status());
    }

    public function testDeclinedAnswerClosesAnUnknownPayment(): void
    {
        $this->world->seedPayment(PaymentStatus::Unknown);
        $this->world->gatewayKnowsDeclinedCharge();

        $result = $this->reconcile();

        self::assertSame(1, $result->checked);
        self::assertSame(0, $result->refunded);
        self::assertSame(PaymentStatus::Declined, $this->world->status());
    }

    public function testRecentPaymentsAndOtherStatusesAreNotChecked(): void
    {
        $this->world->seedPayment(PaymentStatus::Unknown, at: 590);

        self::assertSame(0, $this->reconcile()->checked, 'updated 10 s ago');

        $world = new PaymentWorld();
        $world->clock->modify('2026-05-01T10:10:00Z');
        $world->seedPayment(PaymentStatus::Succeeded);

        $handler = new ReconcilePaymentsHandler($world->payments, $world->gateway, $world->outcomes, $world->recorder(), $world->refunder(), $world->clock);
        self::assertSame(0, $handler(new ReconcilePaymentsCommand(300, 100))->checked);
    }

    public function testGatewayFailureSkipsThePaymentForThisRun(): void
    {
        $this->orderEnded(OrderFactory::failed());
        $this->world->seedPayment(PaymentStatus::Abandoned);
        $this->world->gatewayKnowsSuccessfulCharge();
        $this->world->gateway->failRefunds(1);

        $first = $this->reconcile();

        self::assertSame(0, $first->checked);
        self::assertSame(PaymentStatus::RefundPending, $this->world->status());

        // The stalled refund is picked up by a later run, once it is older than the threshold.
        $this->world->clock->modify('2026-05-01T10:20:00Z');
        $second = $this->reconcile();

        self::assertSame(1, $second->checked);
        self::assertSame(1, $second->refunded);
        self::assertSame(PaymentStatus::Refunded, $this->world->status());
        self::assertSame(1, $this->world->gateway->bookedRefunds());
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function badArguments(): iterable
    {
        yield 'age below 60' => [59, 10];
        yield 'limit zero' => [60, 0];
        yield 'limit too big' => [60, 1001];
    }

    #[DataProvider('badArguments')]
    public function testArgumentsAreRangeChecked(int $olderThan, int $limit): void
    {
        $this->expectException(InvalidInput::class);

        new ReconcilePaymentsCommand($olderThan, $limit);
    }
}
