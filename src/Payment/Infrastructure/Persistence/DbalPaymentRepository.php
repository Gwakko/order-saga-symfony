<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Persistence;

use App\Payment\Application\Port\PaymentRepository;
use App\Payment\Domain\Event\PaymentEvent;
use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentStatus;
use App\Shared\Application\Exception\ConcurrencyConflict;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\PaymentId;
use App\Shared\Domain\Money;
use App\Shared\Infrastructure\Outbox\OutboxWriter;
use App\Shared\Infrastructure\Persistence\DbalTransactionManager;
use App\Shared\Infrastructure\Persistence\DbTimestamp;
use App\Shared\Infrastructure\Persistence\Row;
use Doctrine\DBAL\Connection;

/**
 * `payments`: one row per order (unique `order_id`, INV-06). Not every Payment transition emits an event, so the CAS
 * is `stored + 1 = version`, not `version - count(events)`.
 */
final readonly class DbalPaymentRepository implements PaymentRepository
{
    private const string COLUMNS = 'id, order_id, amount, currency, status, gateway_charge_id, gateway_refund_id, attempts, last_error, created_at, updated_at, version';

    public function __construct(
        private Connection $connection,
        private DbalTransactionManager $transactions,
        private OutboxWriter $outbox,
    ) {}

    public function findByOrder(OrderId $orderId): ?Payment
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM payments WHERE order_id = :order_id',
            ['order_id' => $orderId->value],
        );

        return $row === false ? null : $this->hydrate(new Row($row));
    }

    public function insertOrGet(Payment $payment): Payment
    {
        return $this->transactions->transactional(function () use ($payment): Payment {
            // DO NOTHING instead of catching the violation: a violation would abort an enclosing Postgres transaction.
            $inserted = $this->connection->executeStatement(
                'INSERT INTO payments (id, order_id, amount, currency, status, gateway_charge_id, gateway_refund_id, attempts, last_error, created_at, updated_at, version)
                 VALUES (:id, :order_id, :amount, :currency, :status, :gateway_charge_id, :gateway_refund_id, :attempts, :last_error, :created_at, :updated_at, :version)
                 ON CONFLICT DO NOTHING',
                [
                    'id' => $payment->id->value,
                    'order_id' => $payment->orderId->value,
                    'amount' => $payment->amount->amount,
                    'currency' => $payment->amount->currency->value,
                    'status' => $payment->status->value,
                    'gateway_charge_id' => $payment->gatewayChargeId,
                    'gateway_refund_id' => $payment->gatewayRefundId,
                    'attempts' => $payment->attempts,
                    'last_error' => $payment->lastError,
                    'created_at' => DbTimestamp::format($payment->createdAt),
                    'updated_at' => DbTimestamp::format($payment->updatedAt),
                    'version' => $payment->version,
                ],
            );

            if ($inserted === 1) {
                $this->outbox->append($payment->pendingEvents);

                return $payment;
            }

            return $this->findByOrder($payment->orderId)
                ?? throw new ConcurrencyConflict('Payment', $payment->id->value);
        });
    }

    public function save(Payment $payment): void
    {
        $this->transactions->transactional(function () use ($payment): void {
            $updated = $this->connection->executeStatement(
                'UPDATE payments SET status = :status, gateway_charge_id = :gateway_charge_id, gateway_refund_id = :gateway_refund_id,
                     attempts = :attempts, last_error = :last_error, updated_at = :updated_at, version = :version
                 WHERE id = :id AND version = :expected',
                [
                    'id' => $payment->id->value,
                    'status' => $payment->status->value,
                    'gateway_charge_id' => $payment->gatewayChargeId,
                    'gateway_refund_id' => $payment->gatewayRefundId,
                    'attempts' => $payment->attempts,
                    'last_error' => $payment->lastError,
                    'updated_at' => DbTimestamp::format($payment->updatedAt),
                    'version' => $payment->version,
                    'expected' => $payment->version - 1,
                ],
            );

            if ($updated !== 1) {
                throw new ConcurrencyConflict('Payment', $payment->id->value);
            }

            // One transition per save: the application saves a payment and then continues with the same aggregate
            // (record, requestRefund, markRefunded), which still carries the events of the earlier saves. Only the
            // events of this transition (version above the one just replaced) go to the outbox.
            $this->outbox->append(array_values(array_filter(
                $payment->pendingEvents,
                static fn(PaymentEvent $event): bool => $event->version >= $payment->version,
            )));
        });
    }

    public function findOpenUpdatedBefore(\DateTimeImmutable $before, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT " . self::COLUMNS . " FROM payments
             WHERE status IN ('unknown', 'abandoned', 'refund_pending') AND updated_at < :before
             ORDER BY updated_at, id
             LIMIT :limit",
            ['before' => DbTimestamp::format($before), 'limit' => $limit],
        );

        return array_map(fn(array $row): Payment => $this->hydrate(new Row($row)), $rows);
    }

    private function hydrate(Row $row): Payment
    {
        return Payment::reconstitute(
            PaymentId::fromString($row->string('id')),
            OrderId::fromString($row->string('order_id')),
            Money::of($row->int('amount'), Currency::from($row->string('currency'))),
            PaymentStatus::from($row->string('status')),
            $row->nullableString('gateway_charge_id'),
            $row->nullableString('gateway_refund_id'),
            $row->int('attempts'),
            $row->nullableString('last_error'),
            $row->time('created_at'),
            $row->time('updated_at'),
            $row->int('version'),
        );
    }
}
