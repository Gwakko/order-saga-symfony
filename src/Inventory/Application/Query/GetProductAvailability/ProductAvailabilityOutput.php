<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query\GetProductAvailability;

use App\Shared\Application\Output\MoneyOutput;

final readonly class ProductAvailabilityOutput
{
    /**
     * @param int<0, max> $available keys that can still be reserved
     */
    public function __construct(
        public string $productId,
        public string $name,
        public MoneyOutput $price,
        public int $available,
    ) {}
}
