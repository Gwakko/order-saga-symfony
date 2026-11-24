<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Ordering\Application\Port\ProductCatalog;
use App\Ordering\Application\Port\ProductSnapshot;
use App\Shared\Domain\Identifier\ProductId;

final class InMemoryProductCatalog implements ProductCatalog
{
    /** @var array<string, ProductSnapshot> */
    private array $products = [];

    public function put(ProductSnapshot $product): void
    {
        $this->products[$product->id->value] = $product;
    }

    public function find(ProductId $id): ?ProductSnapshot
    {
        return $this->products[$id->value] ?? null;
    }
}
