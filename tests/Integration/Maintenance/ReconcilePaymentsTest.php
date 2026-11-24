<?php

declare(strict_types=1);

namespace App\Tests\Integration\Maintenance;

use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderStatus;
use App\Ordering\Infrastructure\Persistence\DbalOrderOutcomeReader;
use App\Payment\Application\Command\ReconcilePayments\ReconcilePaymentsCommand;
use App\Payment\Application\Command\ReconcilePayments\ReconcilePaymentsHandler;
use App\Payment\Application\Service\ChargeRecorder;
use App\Payment\Application\Service\PaymentRefunder;
use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Infrastructure\Gateway\HttpPaymentGateway;
use App\Shared\Domain\Currency;
use App\Shared\Domain\FailureReason;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\PaymentId;
use App\Shared\Domain\Identifier\ReservationId;
use App\Shared\Domain\Money;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\Support\GatewayEmulator;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\HttpClient;

/**
 * EC-28 against Postgres and the real gateway emulator: the charge succeeded after the saga gave up, the sweeper refunds it.
 */
#[Group('M4.6')]
final class ReconcilePaymentsTest extends IntegrationTestCase
{
    private GatewayEmulator $emulator;
    private HttpPaymentGateway $gateway;
    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->emulator = GatewayEmulator::fromEnvironment();
        $this->emulator->reset();
        $this->gateway = new HttpPaymentGateway(HttpClient::create(), $this->emulator->baseUri, 2.0);
        $this->clock = new MockClock('2026-05-01T10:10:00Z');
    }

    protected function tearDown(): void
    {
        $this->emulator->reset();
        parent::tearDown();
    }

    #[Group('T-I-21')]
    public function testLateSuccessIsRefunded(): void
    {
        $order = $this->failedOrder();
        $payment = $this->abandonedPayment($order->id->value);

        // The charge timed out for the saga, the bank booked it anyway: the emulator shows it now (late success).
        $this->gateway->charge($payment->id->value, $payment->amount);
        self::assertSame(1, $this->emulator->stats()['charges'][$payment->id->value]['executed']);

        $result = $this->handler()(new ReconcilePaymentsCommand(300, 100));

        self::assertSame(1, $result->checked);
        self::assertSame(1, $result->succeededLate);
        self::assertSame(1, $result->refunded);
        self::assertSame(PaymentStatus::Refunded->value, $this->connection->fetchOne('SELECT status FROM payments WHERE id = :id', ['id' => $payment->id->value]));
        self::assertSame(1, $this->emulator->stats()['refunds']['refund-' . $payment->id->value]['executed'], 'one refund at the gateway');
        self::assertSame(1, $this->countRows('outbox_messages', "event_type = 'OrphanChargeDetected'"));
        self::assertSame(1, $this->countRows('outbox_messages', "event_type = 'PaymentRefunded'"));
    }

    #[Group('T-I-21')]
    public function testSecondRunFindsNothingToDo(): void
    {
        $order = $this->failedOrder();
        $payment = $this->abandonedPayment($order->id->value);
        $this->gateway->charge($payment->id->value, $payment->amount);

        $this->handler()(new ReconcilePaymentsCommand(300, 100));
        $second = $this->handler()(new ReconcilePaymentsCommand(300, 100));

        self::assertSame(0, $second->checked);
        self::assertSame(1, $this->emulator->stats()['refunds']['refund-' . $payment->id->value]['executed']);
    }

    public function testNoChargeAtTheGatewayLeavesThePaymentAlone(): void
    {
        $order = $this->failedOrder();
        $payment = $this->abandonedPayment($order->id->value);

        $result = $this->handler()(new ReconcilePaymentsCommand(300, 100));

        self::assertSame(1, $result->checked);
        self::assertSame(0, $result->succeededLate);
        self::assertSame(PaymentStatus::Abandoned->value, $this->connection->fetchOne('SELECT status FROM payments WHERE id = :id', ['id' => $payment->id->value]));
        self::assertArrayNotHasKey($payment->id->value, $this->emulator->stats()['charges']);
    }

    public function testGatewayOutageSkipsThePaymentForThisRun(): void
    {
        $order = $this->failedOrder();
        $payment = $this->abandonedPayment($order->id->value);
        $this->gateway->charge($payment->id->value, $payment->amount);
        $this->emulator->mode('ok');

        $broken = new HttpPaymentGateway(HttpClient::create(), 'http://127.0.0.1:1', 0.5);
        $result = $this->handler($broken)(new ReconcilePaymentsCommand(300, 100));

        self::assertSame(0, $result->checked);
        self::assertSame(PaymentStatus::Abandoned->value, $this->connection->fetchOne('SELECT status FROM payments WHERE id = :id', ['id' => $payment->id->value]));
    }

    private function handler(?HttpPaymentGateway $gateway = null): ReconcilePaymentsHandler
    {
        $gateway ??= $this->gateway;
        $recorder = new ChargeRecorder($this->infra->payments, $this->infra->transactions, $this->clock);

        return new ReconcilePaymentsHandler(
            $this->infra->payments,
            $gateway,
            new DbalOrderOutcomeReader($this->connection),
            $recorder,
            new PaymentRefunder($this->infra->payments, $gateway, $recorder, $this->infra->transactions, $this->clock),
            $this->clock,
        );
    }

    private function failedOrder(): \App\Ordering\Domain\Order
    {
        $order = $this->placeOrder()
            ->markKeyReserved(ReservationId::fromString(self::uuid()), $this->t0->modify('+1 second'))
            ->startCompensation(FailureReason::PaymentUnavailable, $this->t0->modify('+2 seconds'))
            ->finishCompensation($this->t0->modify('+3 seconds'));
        $this->infra->orders->save($order);

        self::assertSame(OrderStatus::Failed, $order->status);

        return $order;
    }

    private function abandonedPayment(string $orderId): Payment
    {
        $order = OrderId::fromString($orderId);
        $pending = Payment::create(PaymentId::fromString(self::uuid()), $order, Money::positive(1999, Currency::EUR), $this->t0);
        $this->infra->payments->insertOrGet($pending);

        // One transition per save, each on the freshly loaded aggregate (as the handlers do).
        $this->infra->payments->save($this->stored($order)->markUnknown('timeout', $this->t0->modify('+1 second')));
        $this->infra->payments->save($this->stored($order)->abandon($this->t0->modify('+2 seconds')));

        return $this->stored($order);
    }

    private function stored(OrderId $orderId): Payment
    {
        return $this->infra->payments->findByOrder($orderId) ?? throw new \LogicException('payment not stored');
    }
}
