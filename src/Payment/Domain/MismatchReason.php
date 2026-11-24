<?php

declare(strict_types=1);

namespace App\Payment\Domain;

use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;

/**
 * Why a captured charge cannot count as a payment: what the gateway really charged (EC-36, EC-37).
 */
final readonly class MismatchReason
{
    public function __construct(public MismatchKind $kind, public Money $charged) {}

    public function describe(): string
    {
        return sprintf('%s: charged %s %d', $this->kind->value, $this->charged->currency->value, $this->charged->amount);
    }

    /**
     * Reads the reason back from `Payment::$lastError` ("<kind>: charged <CUR> <amount>", see describe()).
     */
    public static function fromLastError(?string $lastError): ?self
    {
        if ($lastError === null || preg_match('/^(\w+): charged ([A-Z]{3}) (\d+)$/D', $lastError, $m) !== 1) {
            return null;
        }

        $kind = MismatchKind::tryFrom($m[1]);
        $currency = Currency::tryFrom($m[2]);

        return $kind === null || $currency === null ? null : new self($kind, Money::of((int) $m[3], $currency));
    }
}
