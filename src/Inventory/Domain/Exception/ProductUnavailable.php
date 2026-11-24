<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;
use App\Shared\Domain\Identifier\ProductId;

final class ProductUnavailable extends DomainError
{
    public function __construct(public readonly ProductId $productId)
    {
        parent::__construct(sprintf('Product %s is not available.', $productId->value));
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::ProductUnavailable;
    }
}
