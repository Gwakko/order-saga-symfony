<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Domain;

use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\PaymentId;
use App\Shared\Domain\Money;

final class PaymentFactory
{
    public const PAYMENT_ID = '0190a5c4-2000-7000-8000-000000000001';
    public const ORDER_ID = '0190a5c4-2000-7000-8000-000000000002';
    public const CHARGE_ID = 'ch_1';

    public static function t0(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-05-01T10:00:00Z');
    }

    public static function at(int $seconds): \DateTimeImmutable
    {
        return self::t0()->modify(sprintf('+%d seconds', $seconds));
    }

    public static function pending(): Payment
    {
        return Payment::create(
            PaymentId::fromString(self::PAYMENT_ID),
            OrderId::fromString(self::ORDER_ID),
            Money::positive(1999, Currency::EUR),
            self::t0(),
        );
    }

    public static function inStatus(PaymentStatus $status): Payment
    {
        $pending = self::pending();

        return match ($status) {
            PaymentStatus::Pending => $pending,
            PaymentStatus::Unknown => $pending->markUnknown('timeout', self::at(1)),
            PaymentStatus::Succeeded => $pending->markSucceeded(self::CHARGE_ID, Currency::EUR, self::at(1)),
            PaymentStatus::Declined => $pending->markDeclined('insufficient_funds', self::at(1)),
            PaymentStatus::Abandoned => $pending->abandon(self::at(1)),
            PaymentStatus::RefundPending => $pending->markSucceeded(self::CHARGE_ID, Currency::EUR, self::at(1))->requestRefund(self::at(2)),
            PaymentStatus::Refunded => $pending->markSucceeded(self::CHARGE_ID, Currency::EUR, self::at(1))->requestRefund(self::at(2))->markRefunded('rf_1', self::at(3)),
        };
    }
}
