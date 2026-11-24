<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Shared\Domain\Identifier\ProductId;

interface ProductCatalog
{
    public function find(ProductId $id): ?ProductSnapshot;
}
