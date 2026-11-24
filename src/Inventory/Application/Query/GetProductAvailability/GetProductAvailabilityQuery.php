<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query\GetProductAvailability;

final readonly class GetProductAvailabilityQuery
{
    public function __construct(public string $productId) {}
}
