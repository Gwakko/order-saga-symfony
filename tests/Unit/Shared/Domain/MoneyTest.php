<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\Currency;
use App\Shared\Domain\DomainErrorCode;
use App\Shared\Domain\Exception\CurrencyMismatch;
use App\Shared\Domain\Exception\MoneyOutOfRange;
use App\Shared\Domain\Exception\MoneyOverflow;
use App\Shared\Domain\Exception\NegativeMoney;
use App\Shared\Domain\Exception\NonPositiveMoney;
use App\Shared\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * T-U-01: range, negatives, zero for positive(), overflow, currency mismatch, subtract below zero (EC-64a/b/g, EC-65, EC-68).
 */
#[Group('T-U-01')]
final class MoneyTest extends TestCase
{
    public function testOfAcceptsBoundaries(): void
    {
        self::assertSame(0, Money::of(0, Currency::EUR)->amount);
        self::assertSame(Money::MAX_AMOUNT, Money::of(Money::MAX_AMOUNT, Currency::EUR)->amount);
    }

    public function testNegativeAmountRejected(): void
    {
        $this->expectException(NegativeMoney::class);
        Money::of(-100, Currency::EUR);
    }

    public function testAmountAboveMaximumRejected(): void
    {
        $this->expectException(MoneyOutOfRange::class);
        Money::of(Money::MAX_AMOUNT + 1, Currency::EUR);
    }

    public function testZeroAllowedForOfButNotForPositive(): void
    {
        self::assertTrue(Money::of(0, Currency::EUR)->isZero());

        $this->expectException(NonPositiveMoney::class);
        Money::positive(0, Currency::EUR);
    }

    public function testPositiveStillRejectsNegativeAndTooLarge(): void
    {
        try {
            Money::positive(-1, Currency::EUR);
            self::fail('Expected NegativeMoney');
        } catch (NegativeMoney $e) {
            self::assertSame(DomainErrorCode::NegativeMoney, $e->code());
        }

        $this->expectException(MoneyOutOfRange::class);
        Money::positive(Money::MAX_AMOUNT + 1, Currency::EUR);
    }

    public function testIntMaxIsOutOfRangeNotFloat(): void
    {
        $this->expectException(MoneyOutOfRange::class);
        Money::of(PHP_INT_MAX, Currency::EUR);
    }

    public function testAddKeepsIntegerAndCurrency(): void
    {
        $sum = Money::of(1999, Currency::EUR)->add(Money::of(1, Currency::EUR));

        self::assertSame(2000, $sum->amount);
        self::assertSame(Currency::EUR, $sum->currency);
    }

    public function testAddAboveMaximumIsOutOfRange(): void
    {
        $this->expectException(MoneyOutOfRange::class);
        Money::of(Money::MAX_AMOUNT, Currency::EUR)->add(Money::of(1, Currency::EUR));
    }

    public function testCheckedSumDetectsIntegerOverflowBeforeAdding(): void
    {
        $this->expectException(MoneyOverflow::class);
        Money::checkedSum(PHP_INT_MAX, 1);
    }

    public function testCheckedSumWithoutOverflow(): void
    {
        self::assertSame(PHP_INT_MAX, Money::checkedSum(PHP_INT_MAX - 1, 1));
    }

    public function testSubtractBelowZeroRejected(): void
    {
        $this->expectException(NegativeMoney::class);
        Money::of(100, Currency::EUR)->subtract(Money::of(101, Currency::EUR));
    }

    public function testSubtractToZeroAllowed(): void
    {
        self::assertTrue(Money::of(100, Currency::EUR)->subtract(Money::of(100, Currency::EUR))->isZero());
    }

    /**
     * @return iterable<string, array{callable(Money, Money): mixed}>
     */
    public static function crossCurrencyOperations(): iterable
    {
        yield 'add' => [static fn(Money $a, Money $b): Money => $a->add($b)];
        yield 'subtract' => [static fn(Money $a, Money $b): Money => $a->subtract($b)];
        yield 'equals' => [static fn(Money $a, Money $b): bool => $a->equals($b)];
        yield 'greaterThan' => [static fn(Money $a, Money $b): bool => $a->greaterThan($b)];
    }

    /**
     * @param callable(Money, Money): mixed $operation
     */
    #[DataProvider('crossCurrencyOperations')]
    public function testDifferentCurrenciesRejected(callable $operation): void
    {
        try {
            $operation(Money::of(100, Currency::EUR), Money::of(100, Currency::USD));
            self::fail('Expected CurrencyMismatch');
        } catch (CurrencyMismatch $e) {
            self::assertSame(DomainErrorCode::CurrencyMismatch, $e->code());
        }
    }

    public function testEqualityAndComparison(): void
    {
        self::assertTrue(Money::of(5, Currency::PLN)->equals(Money::of(5, Currency::PLN)));
        self::assertFalse(Money::of(5, Currency::PLN)->equals(Money::of(6, Currency::PLN)));
        self::assertTrue(Money::of(6, Currency::PLN)->greaterThan(Money::of(5, Currency::PLN)));
        self::assertFalse(Money::of(5, Currency::PLN)->greaterThan(Money::of(5, Currency::PLN)));
    }
}
