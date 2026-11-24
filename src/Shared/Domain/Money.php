<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use App\Shared\Domain\Exception\CurrencyMismatch;
use App\Shared\Domain\Exception\MoneyOutOfRange;
use App\Shared\Domain\Exception\MoneyOverflow;
use App\Shared\Domain\Exception\NegativeMoney;
use App\Shared\Domain\Exception\NonPositiveMoney;

/**
 * Amount in minor units (integer) plus currency. No floats anywhere (INV-01, INV-02).
 */
final readonly class Money
{
    public const MAX_AMOUNT = 100_000_000;

    private function __construct(
        public int $amount,
        public Currency $currency,
    ) {}

    public static function of(int $amount, Currency $currency): self
    {
        if ($amount < 0) {
            throw new NegativeMoney($amount);
        }

        if ($amount > self::MAX_AMOUNT) {
            throw new MoneyOutOfRange($amount);
        }

        return new self($amount, $currency);
    }

    /**
     * Prices and charges: strictly greater than zero.
     */
    public static function positive(int $amount, Currency $currency): self
    {
        $money = self::of($amount, $currency);

        if ($money->amount === 0) {
            throw new NonPositiveMoney($amount);
        }

        return $money;
    }

    /**
     * Overflow-safe addition of two integers (the check is done before adding).
     */
    public static function checkedSum(int $a, int $b): int
    {
        if ($b > 0 && $a > PHP_INT_MAX - $b) {
            throw new MoneyOverflow();
        }

        return $a + $b;
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return self::of(self::checkedSum($this->amount, $other->amount), $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return self::of($this->amount - $other->amount, $this->currency);
    }

    public function equals(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount === $other->amount;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount > $other->amount;
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new CurrencyMismatch($this->currency, $other->currency);
        }
    }
}
