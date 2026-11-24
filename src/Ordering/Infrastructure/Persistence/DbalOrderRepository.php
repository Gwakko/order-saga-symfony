<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence;

use App\Ordering\Application\Port\OrderRepository;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Application\Exception\ConcurrencyConflict;
use App\Shared\Application\Exception\OrderNotFound;
use App\Shared\Domain\Currency;
use App\Shared\Domain\FailureReason;
use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\PaymentId;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Identifier\ProductKeyId;
use App\Shared\Domain\Identifier\ReservationId;
use App\Shared\Domain\Money;
use App\Shared\Infrastructure\Outbox\OutboxWriter;
use App\Shared\Infrastructure\Persistence\DbalTransactionManager;
use App\Shared\Infrastructure\Persistence\DbTimestamp;
use App\Shared\Infrastructure\Persistence\Row;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * `orders` with optimistic locking. Every transition of `Order` carries one event, so the stored version is
 * `version - count(pendingEvents)`; zero means "new order" and becomes an INSERT. State and outbox rows are written in
 * one transaction (INV-10, INV-15).
 */
final readonly class DbalOrderRepository implements OrderRepository
{
    public function __construct(
        private Connection $connection,
        private DbalTransactionManager $transactions,
        private OutboxWriter $outbox,
    ) {}

    public function get(OrderId $id): Order
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM orders WHERE id = :id', ['id' => $id->value]);

        if ($row === false) {
            throw new OrderNotFound($id);
        }

        return $this->hydrate(new Row($row));
    }

    public function save(Order $order): void
    {
        $expected = $order->version - count($order->pendingEvents);

        $this->transactions->transactional(function () use ($order, $expected): void {
            $written = $expected === 0 ? $this->insert($order) : $this->update($order, $expected);

            if ($written !== 1) {
                throw new ConcurrencyConflict('Order', $order->id->value);
            }

            $this->outbox->append($order->pendingEvents);
        });
    }

    private function insert(Order $order): int
    {
        return (int) $this->connection->executeStatement(
            'INSERT INTO orders (id, customer_id, product_id, price_amount, price_currency, status, reservation_id, payment_id,
                 delivered_key_id, failure_reason, compensation_reason, cancellation_requested, created_at, updated_at, completed_at, version)
             VALUES (:id, :customer_id, :product_id, :price_amount, :price_currency, :status, :reservation_id, :payment_id,
                 :delivered_key_id, :failure_reason, :compensation_reason, :cancellation_requested, :created_at, :updated_at, :completed_at, :version)
             ON CONFLICT DO NOTHING',
            $this->params($order),
            ['cancellation_requested' => ParameterType::BOOLEAN],
        );
    }

    private function update(Order $order, int $expected): int
    {
        return (int) $this->connection->executeStatement(
            'UPDATE orders SET status = :status, reservation_id = :reservation_id, payment_id = :payment_id,
                 delivered_key_id = :delivered_key_id, failure_reason = :failure_reason, compensation_reason = :compensation_reason,
                 cancellation_requested = :cancellation_requested, updated_at = :updated_at, completed_at = :completed_at, version = :version
             WHERE id = :id AND version = :expected',
            [
                'id' => $order->id->value,
                'status' => $order->status->value,
                'reservation_id' => $order->reservationId?->value,
                'payment_id' => $order->paymentId?->value,
                'delivered_key_id' => $order->deliveredKeyId?->value,
                'failure_reason' => $order->failureReason?->value,
                'compensation_reason' => $order->compensationReason?->value,
                'cancellation_requested' => $order->cancellationRequested,
                'updated_at' => DbTimestamp::format($order->updatedAt),
                'completed_at' => DbTimestamp::formatNullable($order->completedAt),
                'version' => $order->version,
                'expected' => $expected,
            ],
            ['cancellation_requested' => ParameterType::BOOLEAN],
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    private function params(Order $order): array
    {
        return [
            'id' => $order->id->value,
            'customer_id' => $order->customerId->value,
            'product_id' => $order->productId->value,
            'price_amount' => $order->price->amount,
            'price_currency' => $order->price->currency->value,
            'status' => $order->status->value,
            'reservation_id' => $order->reservationId?->value,
            'payment_id' => $order->paymentId?->value,
            'delivered_key_id' => $order->deliveredKeyId?->value,
            'failure_reason' => $order->failureReason?->value,
            'compensation_reason' => $order->compensationReason?->value,
            'cancellation_requested' => $order->cancellationRequested,
            'created_at' => DbTimestamp::format($order->createdAt),
            'updated_at' => DbTimestamp::format($order->updatedAt),
            'completed_at' => DbTimestamp::formatNullable($order->completedAt),
            'version' => $order->version,
        ];
    }

    private function hydrate(Row $row): Order
    {
        $reservationId = $row->nullableString('reservation_id');
        $paymentId = $row->nullableString('payment_id');
        $keyId = $row->nullableString('delivered_key_id');
        $failure = $row->nullableString('failure_reason');
        $compensation = $row->nullableString('compensation_reason');

        return Order::reconstitute(
            OrderId::fromString($row->string('id')),
            CustomerId::fromString($row->string('customer_id')),
            ProductId::fromString($row->string('product_id')),
            Money::of($row->int('price_amount'), Currency::from($row->string('price_currency'))),
            OrderStatus::from($row->string('status')),
            $reservationId === null ? null : ReservationId::fromString($reservationId),
            $paymentId === null ? null : PaymentId::fromString($paymentId),
            $keyId === null ? null : ProductKeyId::fromString($keyId),
            $failure === null ? null : FailureReason::from($failure),
            $compensation === null ? null : FailureReason::from($compensation),
            $row->bool('cancellation_requested'),
            $row->time('created_at'),
            $row->time('updated_at'),
            $row->nullableTime('completed_at'),
            $row->int('version'),
        );
    }
}
