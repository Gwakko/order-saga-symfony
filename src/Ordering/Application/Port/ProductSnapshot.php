<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Money;

/**
 * What Ordering knows about a catalog product (Inventory owns the product itself).
 */
final readonly class ProductSnapshot
{
    public function __construct(public ProductId $id, public Money $price, public bool $active) {}
}
