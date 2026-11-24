<?php

declare(strict_types=1);

namespace App\Payment\Domain;

use App\Payment\Domain\Event\OrphanChargeDetected;
use App\Payment\Domain\Event\PaymentDeclined;
use App\Payment\Domain\Event\PaymentEvent;
use App\Payment\Domain\Event\PaymentRefunded;
use App\Payment\Domain\Event\PaymentSucceeded;
use App\Payment\Domain\Exception\IllegalPaymentTransition;
use App\Payment\Domain\Exception\InvalidGatewayReference;
use App\Payment\Domain\Exception\PaymentAlreadySucceeded;
use App\Payment\Domain\Exception\PaymentInvariantViolated;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Event\EventId;
use App\Shared\Domain\Exception\CurrencyMismatch;
use App\Shared\Domain\Exception\NonPositiveMoney;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\PaymentId;
use App\Shared\Domain\Money;

/**
 * One payment per order (INV-06). Immutable: a transition returns a new instance with version + 1.
 * The amount is always the order's price (INV-18); it is never taken from a gateway response.
 */
final readonly class Payment
{
    /**
     * @param list<PaymentEvent> $pendingEvents
     */
    private function __construct(
        public PaymentId $id,
        public OrderId $orderId,
        public Money $amount,
        public PaymentStatus $status,
        public ?string $gatewayChargeId,
        public ?string $gatewayRefundId,
        public int $attempts,
        public ?string $lastError,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public int $version,
        public array $pendingEvents,
    ) {
        if ($amount->isZero()) {
            throw new NonPositiveMoney($amount->amount);
        }

        if ($version < 1 || $attempts < 0) {
            throw new PaymentInvariantViolated('version must be >= 1 and attempts >= 0');
        }

        $needsCharge = match ($status) {
            PaymentStatus::Succeeded, PaymentStatus::RefundPending, PaymentStatus::Refunded => true,
            default => false,
        };

        if ($needsCharge && $gatewayChargeId === null) {
            throw new PaymentInvariantViolated(sprintf('%s payment must have a gateway charge id', $status->value));
        }

        if ($status === PaymentStatus::Refunded && $gatewayRefundId === null) {
            throw new PaymentInvariantViolated('refunded payment must have a gateway refund id');
        }
    }

    public static function create(PaymentId $id, OrderId $orderId, Money $amount, \DateTimeImmutable $now): self
    {
        return new self($id, $orderId, $amount, PaymentStatus::Pending, null, null, 0, null, $now, $now, 1, []);
    }

    public static function reconstitute(
        PaymentId $id,
        OrderId $orderId,
        Money $amount,
        PaymentStatus $status,
        ?string $gatewayChargeId,
        ?string $gatewayRefundId,
        int $attempts,
        ?string $lastError,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
        int $version,
    ): self {
        return new self($id, $orderId, $amount, $status, $gatewayChargeId, $gatewayRefundId, $attempts, $lastError, $createdAt, $updatedAt, $version, []);
    }

    /**
     * Counts one gateway call. Only meaningful while the outcome is open (`Pending`, `Unknown`).
     */
    public function recordAttempt(\DateTimeImmutable $now): self
    {
        return match ($this->status) {
            PaymentStatus::Pending, PaymentStatus::Unknown => $this->next($this->status, $now, attempts: $this->attempts + 1),
            default => throw new IllegalPaymentTransition($this->status, $this->status),
        };
    }

    /**
     * Timeout or dropped connection: the charge may or may not exist. Repeating it is a no-op.
     */
    public function markUnknown(string $error, \DateTimeImmutable $now): self
    {
        if ($this->status === PaymentStatus::Unknown) {
            return $this;
        }

        $this->assertCanTransitionTo(PaymentStatus::Unknown);

        return $this->next(PaymentStatus::Unknown, $now, lastError: $error);
    }

    /**
     * Records a confirmed charge. A gateway currency different from the payment currency is rejected (EC-36).
     * From `Abandoned` this records a late success (orphan charge) that reconciliation then refunds (EC-28).
     */
    public function markSucceeded(string $chargeId, Currency $chargedCurrency, \DateTimeImmutable $now): self
    {
        if (trim($chargeId) === '') {
            throw new InvalidGatewayReference('charge id');
        }

        if ($chargedCurrency !== $this->amount->currency) {
            throw new CurrencyMismatch($this->amount->currency, $chargedCurrency);
        }

        if ($this->status === PaymentStatus::Succeeded) {
            return $this->gatewayChargeId === $chargeId ? $this : throw new PaymentAlreadySucceeded($this->id);
        }

        $this->assertCanTransitionTo(PaymentStatus::Succeeded);

        $event = fn(EventId $eventId, int $version): PaymentEvent => $this->status === PaymentStatus::Abandoned
            ? new OrphanChargeDetected($eventId, $this->id, $this->orderId, $this->amount, $version, $now)
            : new PaymentSucceeded($eventId, $this->id, $this->orderId, $this->amount, $version, $now);

        return $this->next(
            PaymentStatus::Succeeded,
            $now,
            gatewayChargeId: $chargeId,
            event: $event,
            eventType: $this->status === PaymentStatus::Abandoned ? 'OrphanChargeDetected' : 'PaymentSucceeded',
        );
    }

    public function markDeclined(string $reason, \DateTimeImmutable $now): self
    {
        $this->assertCanTransitionTo(PaymentStatus::Declined);

        return $this->next(
            PaymentStatus::Declined,
            $now,
            lastError: $reason,
            event: fn(EventId $eventId, int $version): PaymentEvent => new PaymentDeclined($eventId, $this->id, $this->orderId, $this->amount, $version, $now),
            eventType: 'PaymentDeclined',
        );
    }

    /**
     * Compensation before an answer arrived: no charge is known (a later charge is an orphan).
     */
    public function abandon(\DateTimeImmutable $now): self
    {
        $this->assertCanTransitionTo(PaymentStatus::Abandoned);

        return $this->next(PaymentStatus::Abandoned, $now);
    }

    /**
     * Full refund of the charged amount; only after a success.
     */
    public function requestRefund(\DateTimeImmutable $now): self
    {
        $this->assertCanTransitionTo(PaymentStatus::RefundPending);

        return $this->next(PaymentStatus::RefundPending, $now);
    }

    public function markRefunded(string $refundId, \DateTimeImmutable $now): self
    {
        if (trim($refundId) === '') {
            throw new InvalidGatewayReference('refund id');
        }

        $this->assertCanTransitionTo(PaymentStatus::Refunded);

        return $this->next(
            PaymentStatus::Refunded,
            $now,
            gatewayRefundId: $refundId,
            event: fn(EventId $eventId, int $version): PaymentEvent => new PaymentRefunded($eventId, $this->id, $this->orderId, $this->amount, $version, $now),
            eventType: 'PaymentRefunded',
        );
    }

    private function assertCanTransitionTo(PaymentStatus $to): void
    {
        if (!$this->status->canTransitionTo($to)) {
            throw new IllegalPaymentTransition($this->status, $to);
        }
    }

    /**
     * @param (\Closure(EventId, int): PaymentEvent)|null $event
     */
    private function next(
        PaymentStatus $status,
        \DateTimeImmutable $now,
        ?string $gatewayChargeId = null,
        ?string $gatewayRefundId = null,
        ?int $attempts = null,
        ?string $lastError = null,
        ?\Closure $event = null,
        string $eventType = '',
    ): self {
        $version = $this->version + 1;
        $events = $event === null
            ? $this->pendingEvents
            : [...$this->pendingEvents, $event(EventId::derive($this->id->value, $version, $eventType), $version)];

        return new self(
            $this->id,
            $this->orderId,
            $this->amount,
            $status,
            $gatewayChargeId ?? $this->gatewayChargeId,
            $gatewayRefundId ?? $this->gatewayRefundId,
            $attempts ?? $this->attempts,
            $lastError ?? $this->lastError,
            $this->createdAt,
            $now,
            $version,
            $events,
        );
    }
}
