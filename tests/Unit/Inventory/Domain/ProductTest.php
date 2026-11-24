<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Domain;

use App\Inventory\Domain\Exception\InventoryInvariantViolated;
use App\Inventory\Domain\Exception\ProductUnavailable;
use App\Inventory\Domain\Product;
use App\Shared\Domain\Currency;
use App\Shared\Domain\DomainErrorCode;
use App\Shared\Domain\Exception\NonPositiveMoney;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Money;
use PHPUnit\Framework\TestCase;

final class ProductTest extends TestCase
{
    private function product(string $name = 'Game', ?Money $price = null, bool $active = true): Product
    {
        return new Product(
            ProductId::fromString(InventoryFactory::PRODUCT_ID),
            $name,
            $price ?? Money::positive(1999, Currency::EUR),
            $active,
        );
    }

    public function testActiveProductIsAvailable(): void
    {
        $this->product()->assertAvailable();
        $this->expectNotToPerformAssertions();
    }

    public function testInactiveProductIsUnavailable(): void
    {
        try {
            $this->product(active: false)->assertAvailable();
            self::fail('Expected ProductUnavailable');
        } catch (ProductUnavailable $e) {
            self::assertSame(DomainErrorCode::ProductUnavailable, $e->code());
        }
    }

    public function testNameLengthBounds(): void
    {
        self::assertSame(200, mb_strlen($this->product(str_repeat('я', 200))->name));

        $this->expectException(InventoryInvariantViolated::class);
        $this->product(str_repeat('я', 201));
    }

    public function testEmptyNameRejected(): void
    {
        $this->expectException(InventoryInvariantViolated::class);
        $this->product('');
    }

    public function testZeroPriceRejected(): void
    {
        $this->expectException(NonPositiveMoney::class);
        $this->product(price: Money::of(0, Currency::EUR));
    }
}
