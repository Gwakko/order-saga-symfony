<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;

final class NonPositiveMoney extends DomainError
{
    public function __construct(public readonly int $amount)
    {
        parent::__construct(sprintf('Money must be positive, got %d.', $amount));
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::NonPositiveMoney;
    }
}
