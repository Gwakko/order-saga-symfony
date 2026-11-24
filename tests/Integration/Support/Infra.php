<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use App\Inventory\Infrastructure\Persistence\DbalKeyInventory;
use App\Inventory\Infrastructure\Persistence\DbalReservationRepository;
use App\Inventory\Infrastructure\Persistence\InventoryEventNormalizer;
use App\Ordering\Infrastructure\Persistence\DbalOrderRepository;
use App\Ordering\Infrastructure\Persistence\OrderEventNormalizer;
use App\Payment\Infrastructure\Persistence\DbalPaymentRepository;
use App\Payment\Infrastructure\Persistence\PaymentEventNormalizer;
use App\Shared\Infrastructure\Inbox\InboxGuard;
use App\Shared\Infrastructure\Outbox\OutboxPublisher;
use App\Shared\Infrastructure\Outbox\OutboxRelay;
use App\Shared\Infrastructure\Outbox\OutboxWriter;
use App\Shared\Infrastructure\Persistence\DbalTransactionManager;
use Doctrine\DBAL\Connection;
use App\Inventory\Application\Command\ConsumeReservation\ConsumeReservationHandler;
use App\Inventory\Application\Command\ExpireReservations\ExpireReservationsHandler;
use App\Inventory\Application\Command\ReserveKey\ReserveKeyHandler;
use App\Ordering\Application\Command\CancelOrder\CancelOrderHandler;
use App\Ordering\Application\Command\MarkOrderPaid\MarkOrderPaidHandler;
use App\Ordering\Application\OrderMutator;
use App\Ordering\Application\Port\OrderRepository;
use App\Ordering\Application\Port\OrderSagaSignals;
use App\Shared\Infrastructure\Id\SymfonyUuidGenerator;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * The persistence stack on one given connection. Forked processes build their own, the parent test builds one too.
 */
final readonly class Infra
{
    public DbalTransactionManager $transactions;
    public OutboxWriter $outbox;
    public DbalOrderRepository $orders;
    public DbalKeyInventory $keys;
    public DbalReservationRepository $reservations;
    public DbalPaymentRepository $payments;

    public function __construct(public Connection $connection)
    {
        $this->transactions = new DbalTransactionManager($connection);
        $this->outbox = new OutboxWriter($connection, [new OrderEventNormalizer(), new InventoryEventNormalizer(), new PaymentEventNormalizer()]);
        $this->orders = new DbalOrderRepository($connection, $this->transactions, $this->outbox);
        $this->keys = new DbalKeyInventory($connection, $this->transactions, $this->outbox);
        $this->reservations = new DbalReservationRepository($connection, $this->transactions, $this->outbox);
        $this->payments = new DbalPaymentRepository($connection, $this->transactions, $this->outbox);
    }

    public function relay(OutboxPublisher $publisher, ?ClockInterface $clock = null): OutboxRelay
    {
        return new OutboxRelay($this->connection, $this->transactions, $publisher, $clock ?? new MockClock('2026-05-01 12:00:00 UTC'));
    }

    public function inbox(?ClockInterface $clock = null): InboxGuard
    {
        return new InboxGuard($this->connection, $this->transactions, $clock ?? new MockClock('2026-05-01 12:00:00 UTC'));
    }

    public function reserveKey(ClockInterface $clock, int $ttlSeconds = 900): ReserveKeyHandler
    {
        return new ReserveKeyHandler($this->keys, $this->reservations, $this->transactions, new SymfonyUuidGenerator(), $clock, $ttlSeconds);
    }

    public function consumeReservation(ClockInterface $clock): ConsumeReservationHandler
    {
        return new ConsumeReservationHandler($this->keys, $this->reservations, $this->transactions, $clock);
    }

    public function expireReservations(ClockInterface $clock): ExpireReservationsHandler
    {
        return new ExpireReservationsHandler($this->keys, $this->reservations, $this->transactions, $clock);
    }

    public function cancelOrder(OrderSagaSignals $signals, ClockInterface $clock, ?OrderRepository $orders = null): CancelOrderHandler
    {
        return new CancelOrderHandler($orders ?? $this->orders, $signals, $this->transactions, $clock, new NullLogger());
    }

    public function markOrderPaid(ClockInterface $clock, ?OrderRepository $orders = null): MarkOrderPaidHandler
    {
        return new MarkOrderPaidHandler(new OrderMutator($orders ?? $this->orders, $this->transactions, $clock));
    }
}
