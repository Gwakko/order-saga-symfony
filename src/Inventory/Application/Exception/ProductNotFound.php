<?php

declare(strict_types=1);

namespace App\Inventory\Application\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;
use App\Shared\Domain\Identifier\ProductId;

final class ProductNotFound extends DomainError
{
    public function __construct(public readonly ProductId $productId)
    {
        parent::__construct(sprintf('Product %s not found.', $productId->value));
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::ProductNotFound;
    }
}
