<?php

declare(strict_types=1);

namespace App\Tests\Integration\Saga;

use App\Inventory\Application\Command\ConsumeReservation\ConsumeReservationCommand;
use App\Inventory\Application\Command\ReserveKey\ReserveKeyCommand;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderStatus;
use App\Payment\Application\Command\RefundPayment\RefundPaymentCommand;
use App\Payment\Application\Command\RefundPayment\RefundPaymentHandler;
use App\Payment\Application\Service\ChargeRecorder;
use App\Payment\Application\Service\PaymentRefunder;
use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Infrastructure\Gateway\HttpPaymentGateway;
use App\Saga\Infrastructure\Temporal\Config\SagaConfiguration;
use App\Saga\Infrastructure\Temporal\TemporalClientFactory;
use App\Saga\Infrastructure\Temporal\TemporalOrderRefundStarter;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\PaymentId;
use App\Shared\Domain\Identifier\ProductKeyId;
use App\Shared\Domain\Identifier\ReservationId;
use App\Shared\Domain\KeyCode;
use App\Shared\Domain\Money;
use App\Shared\Domain\RefundWindow;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\Support\GatewayEmulator;
use App\Tests\Integration\Support\RefundFixture;
use App\Tests\Integration\Support\TemporalTestWorker;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\HttpClient;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Client\WorkflowClient;

/**
 * Refund of a delivered order against real infrastructure: Postgres (`app_test`), the gateway emulator and, for the
 * retry test, the dev Temporal server with the real worker (see TemporalTestWorker).
 */
#[Group('M6.3')]
final class RefundRetryTest extends IntegrationTestCase
{
    private GatewayEmulator $emulator;
    private HttpPaymentGateway $gateway;
    private ?TemporalTestWorker $worker = null;
    /** @var non-empty-string|null */
    private ?string $workflowId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->emulator = GatewayEmulator::fromEnvironment();
        $this->emulator->reset();
        $this->gateway = new HttpPaymentGateway(HttpClient::create(), $this->emulator->baseUri, 2.0);
    }

    protected function tearDown(): void
    {
        $this->worker?->stop();

        if ($this->workflowId !== null) {
            try {
                $this->client()->newUntypedRunningWorkflowStub($this->workflowId)->terminate('integration test cleanup');
            } catch (\Throwable) {
                // Already finished.
            }
        }

        $this->emulator->reset();
        parent::tearDown();
    }

    /**
     * EC-45: the gateway answers 503 twice, then accepts. The real `RefundWorkflow` (real worker, real retries with
     * 5 s and 10 s of backoff) refunds the order; the gateway executes the refund exactly once.
     */
    #[Group('T-I-24')]
    public function testRefundExecutedOnceAcrossRetries(): void
    {
        [$order, $payment] = $this->fixture()->create(true);
        $this->emulator->mode('http503:2');
        $this->worker = new TemporalTestWorker();
        $this->worker->start($this->temporalAddress());

        $configuration = self::getContainer()->get(SagaConfiguration::class);
        (new TemporalOrderRefundStarter(new TemporalClientFactory($this->temporalAddress()), $configuration, new NullLogger()))->start($order->id);
        $this->workflowId = SagaConfiguration::REFUND_WORKFLOW_ID_PREFIX . $order->id->value;

        $stub = $this->client()->newUntypedRunningWorkflowStub($this->workflowId);
        $result = $stub->getResult('string', 60);

        self::assertSame('Refunded', $result);
        $refund = $this->emulator->stats()['refunds']['refund-' . $payment->id->value];
        self::assertSame(3, $this->refundActivityAttempts(), 'two 503 answers, then the accepted request (the emulator does not count rejected requests)');
        self::assertSame(1, $refund['requests']);
        self::assertSame(1, $refund['executed'], 'the gateway moved the money once');
        self::assertSame(PaymentStatus::Refunded->value, $this->connection->fetchOne('SELECT status FROM payments WHERE id = :id', ['id' => $payment->id->value]));
        self::assertSame(OrderStatus::Refunded, $this->infra->orders->get($order->id)->status);
        self::assertSame('revoked', $this->connection->fetchOne("SELECT status FROM product_keys WHERE id = (SELECT key_id FROM key_reservations WHERE order_id = :id)", ['id' => $order->id->value]));
    }

    /**
     * EC-46: the refund was executed at the gateway, the worker died before `markRefunded` was saved, so the payment
     * is still `RefundPending`. The retry sends the same idempotency key, the gateway answers with the same refund
     * and does not move money again.
     */
    #[Group('T-I-25')]
    public function testCrashAfterRefundBeforeSave(): void
    {
        [, $payment] = $this->fixture()->create(true);
        $stored = $this->infra->payments->findByOrder($payment->orderId) ?? throw new \LogicException('payment not stored');
        $this->infra->payments->save($stored->requestRefund($this->t0->modify('+1 hour')));
        $pending = $this->infra->payments->findByOrder($payment->orderId) ?? throw new \LogicException('payment not stored');
        self::assertSame(PaymentStatus::RefundPending, $pending->status);

        // The refund the dead worker had sent.
        $first = $this->gateway->refund('refund-' . $payment->id->value, $pending->gatewayChargeId ?? '', $pending->amount);

        $output = $this->refundHandler()(new RefundPaymentCommand($payment->orderId->value));

        self::assertSame('Refunded', $output->status->value);
        $refund = $this->emulator->stats()['refunds']['refund-' . $payment->id->value];
        self::assertSame(2, $refund['requests']);
        self::assertSame(1, $refund['executed'], 'the retry did not move money again');
        $stored = $this->infra->payments->findByOrder($payment->orderId) ?? throw new \LogicException('payment not stored');
        self::assertSame(PaymentStatus::Refunded, $stored->status);
        self::assertSame($first->refundId, $stored->gatewayRefundId, 'the same refund id as the first request');
    }

    /** The attempt number of the last start of `RefundPayment`, from the workflow history. */
    private function refundActivityAttempts(): int
    {
        $execution = $this->client()->newUntypedRunningWorkflowStub($this->workflowId ?? throw new \LogicException('The refund was not started.'))->describe()->info->execution;
        $scheduled = '';
        $attempt = 0;

        foreach ($this->client()->getWorkflowHistory($execution)->getEvents() as $event) {
            if ($event->getEventType() === EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED) {
                $scheduled = $event->getActivityTaskScheduledEventAttributes()?->getActivityType()?->getName() ?? '';
            }

            if ($event->getEventType() === EventType::EVENT_TYPE_ACTIVITY_TASK_STARTED && $scheduled === 'RefundPayment') {
                $attempt = $event->getActivityTaskStartedEventAttributes()?->getAttempt() ?? 0;
            }
        }

        return $attempt;
    }

    private function refundHandler(): RefundPaymentHandler
    {
        $clock = new MockClock($this->t0->modify('+2 hours'));
        $recorder = new ChargeRecorder($this->infra->payments, $this->infra->transactions, $clock);

        return new RefundPaymentHandler($this->infra->payments, new PaymentRefunder($this->infra->payments, $this->gateway, $recorder, $this->infra->transactions, $clock));
    }

    private function fixture(): RefundFixture
    {
        return new RefundFixture($this->infra, $this->connection, $this->gateway);
    }

    private function client(): WorkflowClient
    {
        return (new TemporalClientFactory($this->temporalAddress()))->create();
    }

    /**
     * @return non-empty-string
     */
    private function temporalAddress(): string
    {
        $address = $_SERVER['TEMPORAL_ADDRESS'] ?? $_ENV['TEMPORAL_ADDRESS'] ?? 'temporal:7233';

        return is_string($address) && $address !== '' ? $address : 'temporal:7233';
    }
}
