<?php

declare(strict_types=1);

namespace App\Shared\Application\Output;

use App\Shared\Domain\Money;

final readonly class MoneyOutput
{
    public function __construct(public int $amount, public string $currency) {}

    public static function fromMoney(Money $money): self
    {
        return new self($money->amount, $money->currency->value);
    }
}
