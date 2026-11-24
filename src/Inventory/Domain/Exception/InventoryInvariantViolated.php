<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;

final class InventoryInvariantViolated extends DomainError
{
    public function __construct(string $detail)
    {
        parent::__construct('Inventory invariant violated: ' . $detail);
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::InvariantViolated;
    }
}
