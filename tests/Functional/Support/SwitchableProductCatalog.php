<?php

declare(strict_types=1);

namespace App\Tests\Functional\Support;

use App\Inventory\Infrastructure\Persistence\DbalProductCatalog;
use App\Ordering\Application\Port\ProductCatalog;
use App\Ordering\Application\Port\ProductSnapshot;
use App\Shared\Domain\Identifier\ProductId;

/**
 * Test-environment decorator of the catalog: can simulate an infrastructure failure inside the handler, which ends as
 * an unexpected exception and HTTP 500 (EC-07).
 */
final class SwitchableProductCatalog implements ProductCatalog
{
    public bool $failing = false;

    public function __construct(private readonly DbalProductCatalog $inner) {}

    public function find(ProductId $id): ?ProductSnapshot
    {
        if ($this->failing) {
            throw new \RuntimeException('database exploded');
        }

        return $this->inner->find($id);
    }
}
