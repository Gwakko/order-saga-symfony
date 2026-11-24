<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\GraphQL;

use App\Inventory\Application\Query\GetProductAvailability\ProductAvailabilityOutput;
use App\Inventory\Application\Query\GetProductsAvailability\GetProductsAvailabilityQuery;
use App\Inventory\Application\Query\GetProductsAvailability\ProductsAvailabilityOutput;
use App\Shared\Infrastructure\Bus\QueryBus;
use GraphQL\Deferred;

/**
 * DataLoader for products (EC-84). `load()` only queues an id and returns a deferred value; webonyx runs the deferreds
 * after the whole list was resolved, so the first one fetches every queued product with a single query and the rest
 * read the cache. One instance lives for one request.
 */
final class ProductBatchLoader
{
    /** @var array<string, true> */
    private array $queued = [];

    /** @var array<string, ProductAvailabilityOutput|null> */
    private array $loaded = [];

    public function __construct(private readonly QueryBus $queries) {}

    public function load(string $productId): Deferred
    {
        if (!array_key_exists($productId, $this->loaded)) {
            $this->queued[$productId] = true;
        }

        return new Deferred(function () use ($productId): ?ProductAvailabilityOutput {
            $this->fetchQueued();

            return $this->loaded[$productId] ?? null;
        });
    }

    private function fetchQueued(): void
    {
        if ($this->queued === []) {
            return;
        }

        $ids = array_map(strval(...), array_keys($this->queued));
        $this->queued = [];
        $found = $this->queries->execute(new GetProductsAvailabilityQuery($ids), ProductsAvailabilityOutput::class);

        foreach ($ids as $id) {
            $this->loaded[$id] = $found->byId[$id] ?? null;
        }
    }
}
