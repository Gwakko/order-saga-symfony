<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;

final class MoneyOutOfRange extends DomainError
{
    public function __construct(public readonly int $amount)
    {
        parent::__construct(sprintf('Amount %d is out of range 0..%d.', $amount, \App\Shared\Domain\Money::MAX_AMOUNT));
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::MoneyOutOfRange;
    }
}
