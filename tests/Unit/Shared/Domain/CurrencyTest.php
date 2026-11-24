<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\Currency;
use App\Shared\Domain\DomainErrorCode;
use App\Shared\Domain\Exception\UnknownCurrency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CurrencyTest extends TestCase
{
    public function testAllCurrenciesHaveTwoMinorUnits(): void
    {
        foreach (Currency::cases() as $currency) {
            self::assertSame(2, $currency->minorUnits());
        }
    }

    public function testFromCodeParsesKnownCode(): void
    {
        self::assertSame(Currency::UAH, Currency::fromCode('UAH'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownCodes(): iterable
    {
        yield 'lower case' => ['eur'];
        yield 'unknown' => ['JPY'];
        yield 'empty' => [''];
    }

    #[DataProvider('unknownCodes')]
    public function testUnknownCodeRejected(string $code): void
    {
        try {
            Currency::fromCode($code);
            self::fail('Expected UnknownCurrency');
        } catch (UnknownCurrency $e) {
            self::assertSame(DomainErrorCode::UnknownCurrency, $e->code());
        }
    }
}
