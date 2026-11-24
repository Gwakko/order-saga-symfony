<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;

final class NegativeMoney extends DomainError
{
    public function __construct(public readonly int $amount)
    {
        parent::__construct(sprintf('Money must not be negative, got %d.', $amount));
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::NegativeMoney;
    }
}
