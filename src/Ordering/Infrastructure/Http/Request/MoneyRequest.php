<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Http\Request;

use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Money as it arrives: integer minor units and an upper-case ISO code. A float, a numeric string or an overflowing
 * number never reaches the domain (EC-64e/f/g/h).
 */
final readonly class MoneyRequest
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Type('int')]
        #[Assert\Positive]
        #[Assert\LessThanOrEqual(Money::MAX_AMOUNT)]
        public int $amount,
        #[Assert\NotBlank]
        #[Assert\Choice(callback: [self::class, 'currencies'])]
        public string $currency,
    ) {}

    /**
     * @return list<string>
     */
    public static function currencies(): array
    {
        return array_map(static fn(Currency $currency): string => $currency->value, Currency::cases());
    }
}
