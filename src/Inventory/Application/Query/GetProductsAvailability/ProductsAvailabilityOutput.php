<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query\GetProductsAvailability;

use App\Inventory\Application\Query\GetProductAvailability\ProductAvailabilityOutput;

final readonly class ProductsAvailabilityOutput
{
    /**
     * Only products that exist: a missing id is simply absent.
     *
     * @param array<string, ProductAvailabilityOutput> $byId keyed by product id
     */
    public function __construct(public array $byId) {}
}
