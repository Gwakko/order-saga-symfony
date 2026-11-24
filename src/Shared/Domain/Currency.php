<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use App\Shared\Domain\Exception\UnknownCurrency;

enum Currency: string
{
    case EUR = 'EUR';
    case USD = 'USD';
    case PLN = 'PLN';
    case UAH = 'UAH';

    /**
     * Strict, case-sensitive parsing: "eur" is unknown.
     */
    public static function fromCode(string $code): self
    {
        return self::tryFrom($code) ?? throw new UnknownCurrency($code);
    }

    public function minorUnits(): int
    {
        return match ($this) {
            self::EUR, self::USD, self::PLN, self::UAH => 2,
        };
    }
}
