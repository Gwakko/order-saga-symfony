<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Inventory\Application\Command\ConsumeReservation\ConsumeReservationCommand;
use App\Inventory\Application\Command\ReleaseReservation\ReleaseReservationCommand;
use App\Inventory\Application\Command\ReleaseReservation\ReleaseReservationHandler;
use App\Inventory\Application\Command\ReserveKey\ReserveKeyCommand;
use App\Inventory\Infrastructure\Persistence\DbalDeliveredKeyReader;
use App\Inventory\Infrastructure\Persistence\DbalProductCatalog;
use App\Inventory\Infrastructure\Persistence\DbalReservationStatusReader;
use App\Ordering\Application\Command\CompleteOrder\CompleteOrderCommand;
use App\Ordering\Application\Command\CompleteOrder\CompleteOrderHandler;
use App\Ordering\Application\Command\MarkOrderKeyReserved\MarkOrderKeyReservedCommand;
use App\Ordering\Application\Command\MarkOrderKeyReserved\MarkOrderKeyReservedHandler;
use App\Ordering\Application\Command\MarkOrderPaid\MarkOrderPaidCommand;
use App\Ordering\Application\Command\PlaceOrder\PlaceOrderCommand;
use App\Ordering\Application\Command\PlaceOrder\PlaceOrderHandler;
use App\Ordering\Application\OrderMutator;
use App\Ordering\Domain\OrderStatus;
use App\Ordering\Infrastructure\Persistence\DbalOrderPriceReader;
use App\Payment\Application\Command\ChargePayment\ChargePaymentCommand;
use App\Payment\Application\Command\ChargePayment\ChargePaymentHandler;
use App\Payment\Application\Service\ChargeRecorder;
use App\Payment\Infrastructure\Gateway\HttpPaymentGateway;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Infrastructure\Id\SymfonyUuidGenerator;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\Support\GatewayEmulator;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\HttpClient;

/**
 * EC-80, INV-17: the full key code appears in no log record and in no outbox message of a whole happy path
 * (place, reserve, charge at the emulator, pay, consume, complete) plus the defensive release after consumption.
 * Only the delivery read (`orders` / `product_keys`) may hold it.
 */
#[Group('M8.1')]
final class KeyCodeLeakTest extends IntegrationTestCase
{
    private GatewayEmulator $emulator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->emulator = GatewayEmulator::fromEnvironment();
        $this->emulator->reset();
    }

    protected function tearDown(): void
    {
        $this->emulator->reset();
        parent::tearDown();
    }

    #[Group('T-I-42')]
    public function testFullKeyCodeNeverReachesLogsOrOutbox(): void
    {
        $logs = new TestHandler();
        $logger = new Logger('leak-test', [$logs]);
        $product = $this->seedProduct();
        [$key] = $this->seedKeys($product->id, 1);
        $code = $key->code->reveal();
        $clock = new MockClock($this->t0);
        $customer = self::uuid();
        $orderId = self::uuid();
        $mutator = new OrderMutator($this->infra->orders, $this->infra->transactions, $clock);

        (new PlaceOrderHandler($this->infra->orders, new DbalProductCatalog($this->connection), $this->infra->transactions, $clock))(
            new PlaceOrderCommand($orderId, $customer, $product->id->value, 1999, Currency::EUR),
        );
        $reserved = ($this->infra->reserveKey($clock))(new ReserveKeyCommand($orderId, $product->id->value));
        (new MarkOrderKeyReservedHandler($mutator))(new MarkOrderKeyReservedCommand($orderId, $reserved->reservationId));

        $gateway = new HttpPaymentGateway(HttpClient::create(), $this->emulator->baseUri, 2.0);
        $charged = (new ChargePaymentHandler(
            $this->infra->payments,
            new DbalOrderPriceReader($this->connection),
            new DbalReservationStatusReader($this->connection),
            $gateway,
            new ChargeRecorder($this->infra->payments, $this->infra->transactions, $clock),
            $this->infra->transactions,
            new SymfonyUuidGenerator(),
            $clock,
        ))(new ChargePaymentCommand($orderId));
        ($this->infra->markOrderPaid($clock))(new MarkOrderPaidCommand($orderId, $charged->paymentId));

        $consumed = ($this->infra->consumeReservation($clock))(new ConsumeReservationCommand($orderId));
        (new CompleteOrderHandler($mutator, new DbalDeliveredKeyReader($this->connection)))(new CompleteOrderCommand($orderId, $consumed->keyId));

        // The defensive release after the point of no return (EC-22) logs a warning: it must not carry the key either.
        (new ReleaseReservationHandler($this->infra->keys, $this->infra->reservations, $this->infra->transactions, $clock, $logger))(new ReleaseReservationCommand($orderId));

        self::assertSame(OrderStatus::Completed, $this->infra->orders->get(OrderId::fromString($orderId))->status, 'the path really reached delivery');
        self::assertSame($code, $this->connection->fetchOne('SELECT code FROM product_keys WHERE id = :id', ['id' => $consumed->keyId]), 'the key exists in the one place that may hold it');
        self::assertTrue($logs->hasRecordThatContains('', Level::Warning), 'the release logged something, so the log check below is not vacuous');

        foreach ($logs->getRecords() as $record) {
            self::assertStringNotContainsString($code, $record->message);
            self::assertStringNotContainsString($code, (string) json_encode($record->context));
        }

        $payloads = array_map(
            static fn(mixed $payload): string => is_string($payload) ? $payload : throw new \UnexpectedValueException('The outbox payload is not text.'),
            $this->connection->fetchFirstColumn('SELECT payload::text FROM outbox_messages'),
        );
        self::assertGreaterThanOrEqual(6, count($payloads), 'place, reserve, pay, complete, ... all wrote events');
        foreach ($payloads as $payload) {
            self::assertStringNotContainsString($code, $payload);
        }
        self::assertStringContainsString($key->code->masked(), implode('', $payloads), 'the completed event carries the masked key');
    }
}
