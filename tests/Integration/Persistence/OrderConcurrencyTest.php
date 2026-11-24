<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Ordering\Application\Command\CancelOrder\CancelOrderCommand;
use App\Ordering\Application\Command\MarkOrderPaid\MarkOrderPaidCommand;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Application\Exception\ConcurrencyConflict;
use App\Shared\Domain\Identifier\ReservationId;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\Support\ForkedRunner;
use App\Tests\Integration\Support\Infra;
use App\Tests\Integration\Support\RaceOnceOrderRepository;
use App\Tests\Support\Fake\FakeOrderSagaSignals;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;

/**
 * EC-79, INV-15: `CancelOrder` and the saga's `MarkOrderPaid` write one order at the same time. One write wins, the
 * other gets ConcurrencyConflict and is retried (HTTP handler: up to 3 attempts; activity: Temporal). The outcome is
 * `Paid` with `cancellationRequested = true`, whatever the order of the commits.
 */
#[Group('M4.2')]
#[Group('T-I-41')]
final class OrderConcurrencyTest extends IntegrationTestCase
{
    private function keyReservedOrder(): Order
    {
        $placed = $this->placeOrder();
        $this->infra->orders->save($placed);
        $this->infra->orders->save($this->infra->orders->get($placed->id)->markKeyReserved(ReservationId::fromString(self::uuid()), $this->t0));

        return $this->infra->orders->get($placed->id);
    }

    public function testCancelLosesTheRaceAndRetries(): void
    {
        $order = $this->keyReservedOrder();
        $paymentId = self::uuid();
        $signals = new FakeOrderSagaSignals();
        $clock = new MockClock($this->t0->modify('+1 minute'));

        // The payment write sneaks in between CancelOrder's read and its write, once.
        $racing = new RaceOnceOrderRepository($this->infra->orders, fn() => $this->infra->markOrderPaid($clock)(new MarkOrderPaidCommand($order->id->value, $paymentId)));

        $output = $this->infra->cancelOrder($signals, $clock, $racing)(new CancelOrderCommand($order->id->value, $order->customerId->value));

        self::assertTrue($output->cancellationRequested);
        $stored = $this->infra->orders->get($order->id);
        self::assertSame(OrderStatus::Paid, $stored->status);
        self::assertTrue($stored->cancellationRequested);
        self::assertSame($paymentId, $stored->paymentId?->value);
        self::assertSame(4, $stored->version);
        self::assertSame([$order->id->value], $signals->cancellations);
    }

    public function testPaymentLosesTheRaceAndReportsAConflict(): void
    {
        $order = $this->keyReservedOrder();
        $clock = new MockClock($this->t0->modify('+1 minute'));
        $signals = new FakeOrderSagaSignals();
        $paymentId = self::uuid();

        $racing = new RaceOnceOrderRepository($this->infra->orders, fn() => $this->infra->cancelOrder($signals, $clock)(new CancelOrderCommand($order->id->value, $order->customerId->value)));

        try {
            $this->infra->markOrderPaid($clock, $racing)(new MarkOrderPaidCommand($order->id->value, $paymentId));
            self::fail('The activity must see the conflict so that Temporal retries it.');
        } catch (ConcurrencyConflict) {
            // expected: the cancellation committed first
        }

        // The retry reads the cancellation flag and still pays: the outcome is the same.
        $this->infra->markOrderPaid($clock)(new MarkOrderPaidCommand($order->id->value, $paymentId));
        $stored = $this->infra->orders->get($order->id);
        self::assertSame(OrderStatus::Paid, $stored->status);
        self::assertTrue($stored->cancellationRequested);
    }

    #[Group('concurrency')]
    public function testSimultaneousCancelAndPayEndConsistent(): void
    {
        for ($round = 1; $round <= 30; ++$round) {
            $this->connection->executeStatement('TRUNCATE orders, outbox_messages');
            $order = $this->keyReservedOrder();
            $orderId = $order->id->value;
            $customerId = $order->customerId->value;
            $paymentId = self::uuid();

            $cancel = static function (Connection $connection) use ($orderId, $customerId): string {
                (new Infra($connection))->cancelOrder(new FakeOrderSagaSignals(), new MockClock('2026-05-01T10:01:00Z'))(new CancelOrderCommand($orderId, $customerId));

                return 'cancelled';
            };
            // The saga's activity: Temporal retries a ConcurrencyConflict.
            $pay = static function (Connection $connection) use ($orderId, $paymentId): string {
                $handler = (new Infra($connection))->markOrderPaid(new MockClock('2026-05-01T10:01:00Z'));

                for ($attempt = 1; $attempt <= 5; ++$attempt) {
                    try {
                        $handler(new MarkOrderPaidCommand($orderId, $paymentId));

                        return 'paid after ' . $attempt;
                    } catch (ConcurrencyConflict) {
                        // retry
                    }
                }

                return 'gave up';
            };

            $results = ForkedRunner::run($this->connection, [$cancel, $pay]);

            self::assertSame('cancelled', $results[0], sprintf('round %d: %s', $round, implode(' | ', $results)));
            self::assertStringStartsWith('paid', $results[1], sprintf('round %d: %s', $round, implode(' | ', $results)));

            $stored = $this->infra->orders->get($order->id);
            self::assertSame(OrderStatus::Paid, $stored->status);
            self::assertTrue($stored->cancellationRequested);
            self::assertSame(4, $stored->version, 'no write may be lost or doubled');
            self::assertSame(3, $this->countRows('outbox_messages', "aggregate_version >= 2 AND aggregate_id = '" . $orderId . "'"), 'one event per transition');
        }
    }
}
