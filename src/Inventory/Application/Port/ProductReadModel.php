<?php

declare(strict_types=1);

namespace App\Inventory\Application\Port;

use App\Inventory\Application\Query\GetProductAvailability\ProductAvailabilityOutput;
use App\Shared\Domain\Identifier\ProductId;

/**
 * Read side over `product_availability_view` (eventually consistent, no domain objects).
 */
interface ProductReadModel
{
    public function find(ProductId $id): ?ProductAvailabilityOutput;
}
