<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query\GetProductsAvailability;

use App\Inventory\Application\Port\ProductReadModel;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Domain\Identifier\ProductId;

final readonly class GetProductsAvailabilityHandler implements QueryHandler
{
    public function __construct(private ProductReadModel $products) {}

    public function __invoke(GetProductsAvailabilityQuery $query): ProductsAvailabilityOutput
    {
        $ids = array_map(ProductId::fromString(...), array_values(array_unique($query->productIds)));
        $byId = [];

        foreach ($this->products->findMany($ids) as $product) {
            $byId[$product->productId] = $product;
        }

        return new ProductsAvailabilityOutput($byId);
    }
}
