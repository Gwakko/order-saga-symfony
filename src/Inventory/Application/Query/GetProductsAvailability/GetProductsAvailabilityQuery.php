<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query\GetProductsAvailability;

/**
 * Batch form of GetProductAvailability for the GraphQL read side (EC-84: one read for all products of a page).
 */
final readonly class GetProductsAvailabilityQuery
{
    /**
     * @param list<string> $productIds
     */
    public function __construct(public array $productIds) {}
}
