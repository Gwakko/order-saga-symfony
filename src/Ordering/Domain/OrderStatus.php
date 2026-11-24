<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

enum OrderStatus: string
{
    case Pending = 'pending';
    case KeyReserved = 'key_reserved';
    case Paid = 'paid';
    case Completed = 'completed';
    case Compensating = 'compensating';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
    case Refunding = 'refunding';
    case Refunded = 'refunded';
    case ManualReview = 'manual_review';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Cancelled, self::Failed, self::Refunded => true,
            self::Pending, self::KeyReserved, self::Paid, self::Completed,
            self::Compensating, self::Refunding, self::ManualReview => false,
        };
    }

    public function isCancellable(): bool
    {
        return match ($this) {
            self::Pending, self::KeyReserved, self::Paid => true,
            self::Completed, self::Compensating, self::Cancelled, self::Failed,
            self::Refunding, self::Refunded, self::ManualReview => false,
        };
    }

    /**
     * Transition table of 01_domain.md section 3.2 (status level only; guards such as "cancellation requested" live in Order).
     */
    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTargets(), true);
    }

    /**
     * @return list<self>
     */
    private function allowedTargets(): array
    {
        return match ($this) {
            self::Pending => [self::KeyReserved, self::Cancelled, self::Failed, self::ManualReview],
            self::KeyReserved => [self::Paid, self::Compensating, self::ManualReview],
            self::Paid => [self::Completed, self::Compensating, self::ManualReview],
            self::Completed => [self::Refunding],
            self::Compensating => [self::Cancelled, self::Failed, self::ManualReview],
            self::Refunding => [self::Refunded, self::ManualReview],
            self::ManualReview => [self::Cancelled, self::Failed, self::Refunded],
            self::Cancelled, self::Failed, self::Refunded => [],
        };
    }
}
