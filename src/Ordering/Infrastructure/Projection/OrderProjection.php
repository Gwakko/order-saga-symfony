<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Projection;

use App\Ordering\Domain\Event\OrderCancellationRequested;
use App\Ordering\Domain\Event\OrderCancelled;
use App\Ordering\Domain\Event\OrderCompensationStarted;
use App\Ordering\Domain\Event\OrderCompleted;
use App\Ordering\Domain\Event\OrderEvent;
use App\Ordering\Domain\Event\OrderFailed;
use App\Ordering\Domain\Event\OrderKeyReserved;
use App\Ordering\Domain\Event\OrderNeedsManualReview;
use App\Ordering\Domain\Event\OrderPaid;
use App\Ordering\Domain\Event\OrderPlaced;
use App\Ordering\Domain\Event\OrderRefunded;
use App\Ordering\Domain\Event\OrderRefundStarted;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Domain\FailureReason;
use App\Shared\Infrastructure\Inbox\InboxGuard;
use App\Shared\Infrastructure\Outbox\DomainEventCodec;
use App\Shared\Infrastructure\Outbox\OutboxMessage;
use App\Shared\Infrastructure\Persistence\DbTimestamp;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Builds `order_view` from order events (00_overview.md section 7.5, 02_use_cases.md section 4).
 *
 * Idempotent twice over: the inbox drops a repeated message (INV-11), and an event is applied only when its
 * `aggregate_version` is above the stored one, so a late older event is ignored and the status follows the highest
 * version seen (EC-55, INV-15). An event for an order whose `OrderPlaced` has not been projected yet fails with
 * `OrderViewNotReady` and is retried by Messenger.
 */
#[AsMessageHandler]
final readonly class OrderProjection
{
    public const string CONSUMER = 'order_projection';

    public function __construct(
        private Connection $connection,
        private InboxGuard $inbox,
        private DomainEventCodec $codec,
    ) {}

    public function __invoke(OutboxMessage $message): void
    {
        if ($message->aggregateType !== 'order') {
            return;
        }

        $event = $this->codec->toEvent($message);

        if (!$event instanceof OrderEvent) {
            throw new \UnexpectedValueException(sprintf('%s is not an order event.', $event::class));
        }

        $this->inbox->once(self::CONSUMER, $message->messageId, fn() => $this->apply($event));
    }

    private function apply(OrderEvent $event): void
    {
        if ($event instanceof OrderPlaced) {
            $this->insert($event);

            return;
        }

        if ($this->update($event) === 0) {
            $stored = $this->connection->fetchOne('SELECT version FROM order_view WHERE order_id = :id', ['id' => $event->orderId->value]);

            // Stale (an event at or below the stored version) is ignored; a missing row means OrderPlaced is still on its way.
            if ($stored === false) {
                throw new OrderViewNotReady($event->orderId->value, $event->version);
            }
        }
    }

    private function insert(OrderPlaced $event): void
    {
        $at = DbTimestamp::format($event->occurredOn);

        $this->connection->executeStatement(
            "INSERT INTO order_view (order_id, customer_id, product_id, status, price_amount, price_currency, failure_reason,
                                     cancellation_requested, delivered_key_id, created_at, updated_at, completed_at, version)
             VALUES (:order_id, :customer_id, :product_id, :status, :amount, :currency, NULL, false, NULL, :at, :at, NULL, :version)
             ON CONFLICT (order_id) DO NOTHING",
            [
                'order_id' => $event->orderId->value,
                'customer_id' => $event->customerId->value,
                'product_id' => $event->productId->value,
                'status' => OrderStatus::Pending->value,
                'amount' => $event->price->amount,
                'currency' => $event->price->currency->value,
                'at' => $at,
                'version' => $event->version,
            ],
        );
    }

    /**
     * @return int rows changed: 0 when the stored version is already at or above the event's
     */
    private function update(OrderEvent $event): int
    {
        $change = $this->changeOf($event);

        return (int) $this->connection->executeStatement(
            'UPDATE order_view SET
                 status = COALESCE(CAST(:status AS varchar), status),
                 failure_reason = CASE WHEN :set_reason THEN CAST(:reason AS varchar) ELSE failure_reason END,
                 cancellation_requested = cancellation_requested OR :cancel,
                 delivered_key_id = COALESCE(CAST(:key_id AS uuid), delivered_key_id),
                 completed_at = COALESCE(CAST(:completed_at AS timestamptz), completed_at),
                 updated_at = :at,
                 version = :version
             WHERE order_id = :order_id AND version < :version',
            [
                'order_id' => $event->orderId->value,
                'status' => $change['status']?->value,
                'set_reason' => $change['setReason'],
                'reason' => $change['reason']?->value,
                'cancel' => $change['cancel'],
                'key_id' => $change['keyId'],
                'completed_at' => $change['completed'] ? DbTimestamp::format($event->occurredOn) : null,
                'at' => DbTimestamp::format($event->occurredOn),
                'version' => $event->version,
            ],
            ['set_reason' => ParameterType::BOOLEAN, 'cancel' => ParameterType::BOOLEAN],
        );
    }

    /**
     * What the event does to the view. `setReason` writes `reason` (also null, to clear it).
     *
     * @return array{status: ?OrderStatus, setReason: bool, reason: ?FailureReason, cancel: bool, keyId: ?string, completed: bool}
     */
    private function changeOf(OrderEvent $event): array
    {
        $none = ['status' => null, 'setReason' => false, 'reason' => null, 'cancel' => false, 'keyId' => null, 'completed' => false];

        return match (true) {
            $event instanceof OrderKeyReserved => ['status' => OrderStatus::KeyReserved] + $none,
            $event instanceof OrderPaid => ['status' => OrderStatus::Paid] + $none,
            $event instanceof OrderCompleted => ['status' => OrderStatus::Completed, 'keyId' => $event->keyId->value, 'completed' => true] + $none,
            $event instanceof OrderCompensationStarted => ['status' => OrderStatus::Compensating] + $none,
            $event instanceof OrderFailed => ['status' => OrderStatus::Failed, 'setReason' => true, 'reason' => $event->reason] + $none,
            $event instanceof OrderCancelled => ['status' => OrderStatus::Cancelled, 'setReason' => true, 'reason' => FailureReason::CancelledByCustomer, 'cancel' => true] + $none,
            $event instanceof OrderCancellationRequested => ['cancel' => true] + $none,
            $event instanceof OrderRefundStarted => ['status' => OrderStatus::Refunding] + $none,
            $event instanceof OrderRefunded => ['status' => OrderStatus::Refunded, 'setReason' => true] + $none,
            $event instanceof OrderNeedsManualReview => ['status' => OrderStatus::ManualReview, 'setReason' => true, 'reason' => $event->reason] + $none,
            default => throw new \UnexpectedValueException(sprintf('Unhandled order event %s.', $event::class)),
        };
    }
}
