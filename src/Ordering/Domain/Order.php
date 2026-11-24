<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Ordering\Domain\Event\OrderCancelled;
use App\Ordering\Domain\Event\OrderCancellationRequested;
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
use App\Ordering\Domain\Exception\IllegalOrderTransition;
use App\Ordering\Domain\Exception\OrderInvariantViolated;
use App\Ordering\Domain\Exception\OrderNotCancellable;
use App\Ordering\Domain\Exception\OrderNotRefundable;
use App\Ordering\Domain\Exception\PriceChanged;
use App\Ordering\Domain\Exception\RefundWindowClosed;
use App\Shared\Domain\Event\EventId;
use App\Shared\Domain\Exception\CurrencyMismatch;
use App\Shared\Domain\Exception\NonPositiveMoney;
use App\Shared\Domain\FailureReason;
use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\PaymentId;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Identifier\ProductKeyId;
use App\Shared\Domain\Identifier\ReservationId;
use App\Shared\Domain\KeyCode;
use App\Shared\Domain\Money;
use App\Shared\Domain\RefundWindow;

/**
 * Immutable aggregate: every transition returns a new instance with version + 1 and one more pending event.
 * Idempotent repeats return the same instance (no event, no version bump). See 01_domain.md section 3.
 */
final readonly class Order
{
    /**
     * @param list<OrderEvent> $pendingEvents
     */
    private function __construct(
        public OrderId $id,
        public CustomerId $customerId,
        public ProductId $productId,
        public Money $price,
        public OrderStatus $status,
        public ?ReservationId $reservationId,
        public ?PaymentId $paymentId,
        public ?ProductKeyId $deliveredKeyId,
        public ?FailureReason $failureReason,
        public ?FailureReason $compensationReason,
        public bool $cancellationRequested,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ?\DateTimeImmutable $completedAt,
        public int $version,
        public array $pendingEvents,
    ) {
        $this->assertInvariants();
    }

    /**
     * Creates a new order. The price is the catalog price (INV-13); the client's expected price must match it.
     */
    public static function place(
        OrderId $id,
        CustomerId $customerId,
        ProductId $productId,
        Money $catalogPrice,
        Money $expectedPrice,
        \DateTimeImmutable $now,
    ): self {
        if ($expectedPrice->currency !== $catalogPrice->currency) {
            throw new CurrencyMismatch($catalogPrice->currency, $expectedPrice->currency);
        }

        if ($catalogPrice->isZero()) {
            throw new NonPositiveMoney($catalogPrice->amount);
        }

        if (!$expectedPrice->equals($catalogPrice)) {
            throw new PriceChanged($catalogPrice);
        }

        return new self(
            id: $id,
            customerId: $customerId,
            productId: $productId,
            price: $catalogPrice,
            status: OrderStatus::Pending,
            reservationId: null,
            paymentId: null,
            deliveredKeyId: null,
            failureReason: null,
            compensationReason: null,
            cancellationRequested: false,
            createdAt: $now,
            updatedAt: $now,
            completedAt: null,
            version: 1,
            pendingEvents: [new OrderPlaced(
                EventId::derive($id->value, 1, 'OrderPlaced'),
                $id,
                1,
                $now,
                $customerId,
                $productId,
                $catalogPrice,
            )],
        );
    }

    /**
     * Rebuilds a stored order (no pending events). Violated invariants raise OrderInvariantViolated.
     */
    public static function reconstitute(
        OrderId $id,
        CustomerId $customerId,
        ProductId $productId,
        Money $price,
        OrderStatus $status,
        ?ReservationId $reservationId,
        ?PaymentId $paymentId,
        ?ProductKeyId $deliveredKeyId,
        ?FailureReason $failureReason,
        ?FailureReason $compensationReason,
        bool $cancellationRequested,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
        ?\DateTimeImmutable $completedAt,
        int $version,
    ): self {
        return new self(
            $id,
            $customerId,
            $productId,
            $price,
            $status,
            $reservationId,
            $paymentId,
            $deliveredKeyId,
            $failureReason,
            $compensationReason,
            $cancellationRequested,
            $createdAt,
            $updatedAt,
            $completedAt,
            $version,
            [],
        );
    }

    public function markKeyReserved(ReservationId $reservationId, \DateTimeImmutable $now): self
    {
        if ($this->status === OrderStatus::KeyReserved && $this->reservationId?->equals($reservationId) === true) {
            return $this;
        }

        $this->assertCanTransitionTo(OrderStatus::KeyReserved);

        return $this->next(
            OrderStatus::KeyReserved,
            $now,
            fn(EventId $eventId, int $version): OrderEvent => new OrderKeyReserved($eventId, $this->id, $version, $now, $reservationId),
            'OrderKeyReserved',
            reservationId: $reservationId,
        );
    }

    public function markPaid(PaymentId $paymentId, \DateTimeImmutable $now): self
    {
        if ($this->status === OrderStatus::Paid && $this->paymentId?->equals($paymentId) === true) {
            return $this;
        }

        $this->assertCanTransitionTo(OrderStatus::Paid);

        return $this->next(
            OrderStatus::Paid,
            $now,
            fn(EventId $eventId, int $version): OrderEvent => new OrderPaid($eventId, $this->id, $version, $now, $paymentId),
            'OrderPaid',
            paymentId: $paymentId,
        );
    }

    /**
     * Hands the key over. Only the masked form of the code is kept in the event (INV-17).
     */
    public function complete(ProductKeyId $keyId, KeyCode $keyCode, \DateTimeImmutable $now): self
    {
        if ($this->status === OrderStatus::Completed && $this->deliveredKeyId?->equals($keyId) === true) {
            return $this;
        }

        $this->assertCanTransitionTo(OrderStatus::Completed);

        return $this->next(
            OrderStatus::Completed,
            $now,
            fn(EventId $eventId, int $version): OrderEvent => new OrderCompleted($eventId, $this->id, $version, $now, $keyId, $keyCode->masked()),
            'OrderCompleted',
            deliveredKeyId: $keyId,
            completedAt: $now,
        );
    }

    public function requestCancellation(\DateTimeImmutable $now): self
    {
        return match ($this->status) {
            OrderStatus::Pending, OrderStatus::KeyReserved, OrderStatus::Paid => $this->cancellationRequested
                ? $this
                : $this->next(
                    $this->status,
                    $now,
                    fn(EventId $eventId, int $version): OrderEvent => new OrderCancellationRequested($eventId, $this->id, $version, $now),
                    'OrderCancellationRequested',
                    cancellationRequested: true,
                ),
            OrderStatus::Compensating, OrderStatus::Cancelled => $this,
            OrderStatus::Completed, OrderStatus::Refunding, OrderStatus::Refunded,
            OrderStatus::Failed, OrderStatus::ManualReview => throw new OrderNotCancellable($this->status),
        };
    }

    public function cancelBeforeStart(\DateTimeImmutable $now): self
    {
        $this->assertCanTransitionTo(OrderStatus::Cancelled);

        return $this->next(
            OrderStatus::Cancelled,
            $now,
            fn(EventId $eventId, int $version): OrderEvent => new OrderCancelled($eventId, $this->id, $version, $now),
            'OrderCancelled',
            failureReason: FailureReason::CancelledByCustomer,
            cancellationRequested: true,
        );
    }

    public function fail(FailureReason $reason, \DateTimeImmutable $now): self
    {
        if ($this->status !== OrderStatus::Pending) {
            throw new IllegalOrderTransition($this->status, OrderStatus::Failed);
        }

        return $this->next(
            OrderStatus::Failed,
            $now,
            fn(EventId $eventId, int $version): OrderEvent => new OrderFailed($eventId, $this->id, $version, $now, $reason),
            'OrderFailed',
            failureReason: $reason,
        );
    }

    public function startCompensation(FailureReason $reason, \DateTimeImmutable $now): self
    {
        if ($this->status === OrderStatus::Compensating) {
            return $this;
        }

        $this->assertCanTransitionTo(OrderStatus::Compensating);

        return $this->next(
            OrderStatus::Compensating,
            $now,
            fn(EventId $eventId, int $version): OrderEvent => new OrderCompensationStarted($eventId, $this->id, $version, $now, $reason),
            'OrderCompensationStarted',
            compensationReason: $reason,
        );
    }

    /**
     * Ends compensation: `Cancelled` if the customer asked for cancellation, `Failed` (with the compensation reason) otherwise.
     */
    public function finishCompensation(\DateTimeImmutable $now): self
    {
        $target = $this->cancellationRequested ? OrderStatus::Cancelled : OrderStatus::Failed;

        if ($this->status === $target) {
            return $this;
        }

        if ($this->status !== OrderStatus::Compensating) {
            throw new IllegalOrderTransition($this->status, $target);
        }

        if ($target === OrderStatus::Cancelled) {
            return $this->next(
                OrderStatus::Cancelled,
                $now,
                fn(EventId $eventId, int $version): OrderEvent => new OrderCancelled($eventId, $this->id, $version, $now),
                'OrderCancelled',
                failureReason: FailureReason::CancelledByCustomer,
            );
        }

        $reason = $this->compensationReason ?? throw new OrderInvariantViolated('compensating order has no compensation reason');

        return $this->next(
            OrderStatus::Failed,
            $now,
            fn(EventId $eventId, int $version): OrderEvent => new OrderFailed($eventId, $this->id, $version, $now, $reason),
            'OrderFailed',
            failureReason: $reason,
        );
    }

    public function startRefund(\DateTimeImmutable $now, RefundWindow $window): self
    {
        if ($this->status !== OrderStatus::Completed) {
            throw new OrderNotRefundable($this->status);
        }

        if ($this->completedAt === null || !$window->isOpen($this->completedAt, $now)) {
            throw new RefundWindowClosed();
        }

        return $this->next(
            OrderStatus::Refunding,
            $now,
            fn(EventId $eventId, int $version): OrderEvent => new OrderRefundStarted($eventId, $this->id, $version, $now),
            'OrderRefundStarted',
        );
    }

    public function finishRefund(\DateTimeImmutable $now): self
    {
        $this->assertCanTransitionTo(OrderStatus::Refunded);

        return $this->next(
            OrderStatus::Refunded,
            $now,
            fn(EventId $eventId, int $version): OrderEvent => new OrderRefunded($eventId, $this->id, $version, $now),
            'OrderRefunded',
        );
    }

    public function requireManualReview(FailureReason $reason, string $step, \DateTimeImmutable $now): self
    {
        $this->assertCanTransitionTo(OrderStatus::ManualReview);

        return $this->next(
            OrderStatus::ManualReview,
            $now,
            fn(EventId $eventId, int $version): OrderEvent => new OrderNeedsManualReview($eventId, $this->id, $version, $now, $reason, $step),
            'OrderNeedsManualReview',
            failureReason: $reason,
        );
    }

    /**
     * An operator closes a manual-review order as `Cancelled`, `Failed` or `Refunded`.
     * `Refunded` is only possible if a key had been delivered (the order went through `Completed`).
     */
    public function resolveManually(OrderStatus $target, \DateTimeImmutable $now): self
    {
        if ($this->status !== OrderStatus::ManualReview || !$this->status->canTransitionTo($target)) {
            throw new IllegalOrderTransition($this->status, $target);
        }

        return match ($target) {
            OrderStatus::Cancelled => $this->next(
                OrderStatus::Cancelled,
                $now,
                fn(EventId $eventId, int $version): OrderEvent => new OrderCancelled($eventId, $this->id, $version, $now),
                'OrderCancelled',
                failureReason: FailureReason::CancelledByCustomer,
            ),
            OrderStatus::Failed => $this->next(
                OrderStatus::Failed,
                $now,
                fn(EventId $eventId, int $version): OrderEvent => new OrderFailed(
                    $eventId,
                    $this->id,
                    $version,
                    $now,
                    $this->failureReason ?? throw new OrderInvariantViolated('manual review order has no failure reason'),
                ),
                'OrderFailed',
            ),
            OrderStatus::Refunded => $this->deliveredKeyId === null
                ? throw new IllegalOrderTransition($this->status, $target)
                : $this->next(
                    OrderStatus::Refunded,
                    $now,
                    fn(EventId $eventId, int $version): OrderEvent => new OrderRefunded($eventId, $this->id, $version, $now),
                    'OrderRefunded',
                    clearFailureReason: true,
                ),
            default => throw new IllegalOrderTransition($this->status, $target),
        };
    }

    private function assertCanTransitionTo(OrderStatus $to): void
    {
        if (!$this->status->canTransitionTo($to)) {
            throw new IllegalOrderTransition($this->status, $to);
        }
    }

    /**
     * @param \Closure(EventId, int): OrderEvent $event builds the event for the new version
     */
    private function next(
        OrderStatus $status,
        \DateTimeImmutable $now,
        \Closure $event,
        string $eventType,
        ?ReservationId $reservationId = null,
        ?PaymentId $paymentId = null,
        ?ProductKeyId $deliveredKeyId = null,
        ?FailureReason $failureReason = null,
        ?FailureReason $compensationReason = null,
        ?bool $cancellationRequested = null,
        ?\DateTimeImmutable $completedAt = null,
        bool $clearFailureReason = false,
    ): self {
        $version = $this->version + 1;

        return new self(
            $this->id,
            $this->customerId,
            $this->productId,
            $this->price,
            $status,
            $reservationId ?? $this->reservationId,
            $paymentId ?? $this->paymentId,
            $deliveredKeyId ?? $this->deliveredKeyId,
            $clearFailureReason ? null : ($failureReason ?? $this->failureReason),
            $compensationReason ?? $this->compensationReason,
            $cancellationRequested ?? $this->cancellationRequested,
            $this->createdAt,
            $now,
            $completedAt ?? $this->completedAt,
            $version,
            [...$this->pendingEvents, $event(EventId::derive($this->id->value, $version, $eventType), $version)],
        );
    }

    private function assertInvariants(): void
    {
        if ($this->version < 1) {
            throw new OrderInvariantViolated('version must be >= 1');
        }

        $needsFailureReason = match ($this->status) {
            OrderStatus::Cancelled, OrderStatus::Failed, OrderStatus::ManualReview => true,
            default => false,
        };

        if ($needsFailureReason !== ($this->failureReason !== null)) {
            throw new OrderInvariantViolated(sprintf('failureReason must be set exactly for Cancelled/Failed/ManualReview, status is %s', $this->status->value));
        }

        if ($this->status === OrderStatus::Cancelled && $this->failureReason !== FailureReason::CancelledByCustomer) {
            throw new OrderInvariantViolated('Cancelled order must have reason CancelledByCustomer');
        }

        if ($this->status === OrderStatus::Compensating && $this->compensationReason === null) {
            throw new OrderInvariantViolated('Compensating order must have a compensation reason');
        }

        $delivered = match ($this->status) {
            OrderStatus::Completed, OrderStatus::Refunding => true,
            default => false,
        };

        if ($delivered && ($this->reservationId === null || $this->paymentId === null || $this->deliveredKeyId === null || $this->completedAt === null)) {
            throw new OrderInvariantViolated(sprintf('%s order must have reservation, payment, delivered key and completedAt', $this->status->value));
        }
    }
}
