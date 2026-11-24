<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Payment\Application\Port\PaymentRepository;
use App\Payment\Domain\Event\PaymentEvent;
use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentStatus;
use App\Shared\Application\Exception\ConcurrencyConflict;
use App\Shared\Domain\Identifier\OrderId;

final class InMemoryPaymentRepository implements PaymentRepository
{
    /** @var array<string, Payment> keyed by order id */
    private array $payments = [];

    /** @var list<PaymentEvent> */
    public array $events = [];

    public function findByOrder(OrderId $orderId): ?Payment
    {
        return $this->payments[$orderId->value] ?? null;
    }

    public function insertOrGet(Payment $payment): Payment
    {
        $existing = $this->payments[$payment->orderId->value] ?? null;

        if ($existing !== null) {
            return $existing;
        }

        array_push($this->events, ...$payment->pendingEvents);
        $this->payments[$payment->orderId->value] = self::detach($payment);

        return self::detach($payment);
    }

    public function save(Payment $payment): void
    {
        $stored = $this->payments[$payment->orderId->value] ?? null;

        // One transition per save (UPDATE ... WHERE version = version - 1): chains of silent transitions are caught here.
        if ($stored === null || $stored->version + 1 !== $payment->version) {
            throw new ConcurrencyConflict('Payment', $payment->id->value);
        }

        array_push($this->events, ...$payment->pendingEvents);
        $this->payments[$payment->orderId->value] = self::detach($payment);
    }

    public function findOpenUpdatedBefore(\DateTimeImmutable $before, int $limit): array
    {
        $found = array_values(array_filter(
            $this->payments,
            static fn(Payment $p): bool => in_array($p->status, [PaymentStatus::Unknown, PaymentStatus::Abandoned, PaymentStatus::RefundPending], true) && $p->updatedAt < $before,
        ));
        usort($found, static fn(Payment $a, Payment $b): int => $a->updatedAt <=> $b->updatedAt);

        return array_slice($found, 0, $limit);
    }

    public function seed(Payment $payment): void
    {
        $this->payments[$payment->orderId->value] = self::detach($payment);
    }

    private static function detach(Payment $payment): Payment
    {
        return Payment::reconstitute(
            $payment->id,
            $payment->orderId,
            $payment->amount,
            $payment->status,
            $payment->gatewayChargeId,
            $payment->gatewayRefundId,
            $payment->attempts,
            $payment->lastError,
            $payment->createdAt,
            $payment->updatedAt,
            $payment->version,
        );
    }
}
