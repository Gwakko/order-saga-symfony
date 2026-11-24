<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query\GetProductAvailability;

use App\Inventory\Application\Exception\ProductNotFound;
use App\Inventory\Application\Port\ProductReadModel;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Domain\Identifier\ProductId;

final readonly class GetProductAvailabilityHandler implements QueryHandler
{
    public function __construct(private ProductReadModel $products) {}

    public function __invoke(GetProductAvailabilityQuery $query): ProductAvailabilityOutput
    {
        $id = ProductId::fromString($query->productId);

        return $this->products->find($id) ?? throw new ProductNotFound($id);
    }
}
