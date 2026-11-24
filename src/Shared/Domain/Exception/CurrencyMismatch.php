<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;
use App\Shared\Domain\Currency;

final class CurrencyMismatch extends DomainError
{
    public function __construct(public readonly Currency $left, public readonly Currency $right)
    {
        parent::__construct(sprintf('Currency mismatch: %s vs %s.', $left->value, $right->value));
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::CurrencyMismatch;
    }
}
