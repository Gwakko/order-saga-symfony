<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Inventory\Application\Port\ProductReadModel;
use App\Inventory\Application\Query\GetProductAvailability\ProductAvailabilityOutput;
use App\Shared\Domain\Identifier\ProductId;

final class InMemoryProductReadModel implements ProductReadModel
{
    /** @var array<string, ProductAvailabilityOutput> */
    private array $products = [];

    public function put(ProductAvailabilityOutput $product): void
    {
        $this->products[$product->productId] = $product;
    }

    public function find(ProductId $id): ?ProductAvailabilityOutput
    {
        return $this->products[$id->value] ?? null;
    }
}
