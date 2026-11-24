<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;

final class OrderInvariantViolated extends DomainError
{
    public function __construct(string $detail)
    {
        parent::__construct('Order invariant violated: ' . $detail);
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::InvariantViolated;
    }
}
