<?php

declare(strict_types=1);

namespace App\Payment\Domain;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Unknown = 'unknown';
    case Succeeded = 'succeeded';
    case Declined = 'declined';
    case Abandoned = 'abandoned';
    case RefundPending = 'refund_pending';
    case Refunded = 'refunded';

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
            self::Pending => [self::Unknown, self::Succeeded, self::Declined, self::Abandoned],
            self::Unknown => [self::Succeeded, self::Declined, self::Abandoned],
            self::Succeeded => [self::RefundPending],
            self::RefundPending => [self::Refunded],
            self::Abandoned => [self::Succeeded],
            self::Declined, self::Refunded => [],
        };
    }
}
