<?php

declare(strict_types=1);

namespace App\Inventory\Domain;

use App\Inventory\Domain\Exception\InventoryInvariantViolated;
use App\Inventory\Domain\Exception\ProductUnavailable;
use App\Shared\Domain\Exception\NonPositiveMoney;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Money;

final readonly class Product
{
    private const MAX_NAME_LENGTH = 200;

    public function __construct(
        public ProductId $id,
        public string $name,
        public Money $price,
        public bool $active,
    ) {
        $length = mb_strlen($name);

        if ($length < 1 || $length > self::MAX_NAME_LENGTH) {
            throw new InventoryInvariantViolated(sprintf('product name must be 1..%d characters, got %d', self::MAX_NAME_LENGTH, $length));
        }

        if ($price->isZero()) {
            throw new NonPositiveMoney($price->amount);
        }
    }

    /**
     * An inactive product cannot be ordered.
     */
    public function assertAvailable(): void
    {
        if (!$this->active) {
            throw new ProductUnavailable($this->id);
        }
    }
}
