<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment;

use App\Inventory\Application\Command\ReserveKey\ReserveKeyCommand;
use App\Inventory\Infrastructure\Persistence\DbalReservationStatusReader;
use App\Ordering\Infrastructure\Persistence\DbalOrderPriceReader;
use App\Payment\Application\Command\ChargePayment\ChargePaymentCommand;
use App\Payment\Application\Command\ChargePayment\ChargePaymentHandler;
use App\Payment\Application\Port\PaymentRepository;
use App\Payment\Application\Service\ChargeRecorder;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Infrastructure\Gateway\HttpPaymentGateway;
use App\Shared\Infrastructure\Id\SymfonyUuidGenerator;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\Support\CrashOnSucceededPaymentRepository;
use App\Tests\Integration\Support\GatewayEmulator;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\HttpClient;

/**
 * EC-09 against Postgres and the real gateway emulator: the gateway charged, the worker died before `Succeeded` was
 * stored; the retry reaches the gateway with the same idempotency key and the money moves once.
 */
#[Group('M8.1')]
final class ChargePaymentIdempotencyTest extends IntegrationTestCase
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
        $this->clock = new MockClock($this->t0->modify('+10 seconds'));
    }

    protected function tearDown(): void
    {
        $this->emulator->reset();
        parent::tearDown();
    }

    #[Group('T-I-10')]
    public function testRetryAfterCrashDoesNotDoubleCharge(): void
    {
        $orderId = $this->orderWithActiveReservation();

        try {
            $this->handler(new CrashOnSucceededPaymentRepository($this->infra->payments))(new ChargePaymentCommand($orderId));
            self::fail('The first run must die before it stores the success.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('simulated worker crash', $e->getMessage());
        }

        $stored = $this->connection->fetchAssociative('SELECT id, status, gateway_charge_id FROM payments WHERE order_id = :id', ['id' => $orderId]);
        self::assertIsArray($stored);
        self::assertSame(PaymentStatus::Pending->value, $stored['status'], 'the database has not heard about the charge');
        self::assertNull($stored['gateway_charge_id']);
        $paymentId = $stored['id'];
        self::assertIsString($paymentId);
        self::assertSame(1, $this->debits(), 'but the gateway has booked it');

        $output = $this->handler($this->infra->payments)(new ChargePaymentCommand($orderId));

        self::assertSame(PaymentStatus::Succeeded, $output->status);
        self::assertSame($paymentId, $output->paymentId, 'the retry works on the same payment');
        self::assertSame(1, $this->debits(), 'the same idempotency key: one debit in total, whatever key the retry used');
        self::assertSame(['requests' => 2, 'executed' => 1], $this->emulator->stats()['charges'][$paymentId], 'and the retry reused the payment id as the key');
        self::assertSame(1, $this->countRows('payments', sprintf("order_id = '%s'", $orderId)));
        $charged = $this->gateway->findCharge($paymentId);
        self::assertNotNull($charged);
        self::assertSame($charged->chargeId, $this->connection->fetchOne('SELECT gateway_charge_id FROM payments WHERE id = :id', ['id' => $paymentId]), 'the stored charge is the gateway\'s charge');
        self::assertSame(1, $this->countRows('outbox_messages', "event_type = 'PaymentSucceeded'"));
    }

    private function debits(): int
    {
        return array_sum(array_column($this->emulator->stats()['charges'], 'executed'));
    }

    private function orderWithActiveReservation(): string
    {
        $product = $this->seedProduct();
        $this->seedKeys($product->id, 1);
        $order = $this->placeOrder(productId: $product->id);
        $this->infra->orders->save($order);
        ($this->infra->reserveKey(new MockClock($this->t0)))(new ReserveKeyCommand($order->id->value, $product->id->value));

        return $order->id->value;
    }

    private function handler(PaymentRepository $payments): ChargePaymentHandler
    {
        return new ChargePaymentHandler(
            $payments,
            new DbalOrderPriceReader($this->connection),
            new DbalReservationStatusReader($this->connection),
            $this->gateway,
            new ChargeRecorder($payments, $this->infra->transactions, $this->clock),
            $this->infra->transactions,
            new SymfonyUuidGenerator(),
            $this->clock,
        );
    }
}
