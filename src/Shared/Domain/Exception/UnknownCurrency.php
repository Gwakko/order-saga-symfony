<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;

final class UnknownCurrency extends DomainError
{
    public function __construct(public readonly string $currencyCode)
    {
        parent::__construct(sprintf('Unknown currency "%s".', $currencyCode));
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::UnknownCurrency;
    }
}
