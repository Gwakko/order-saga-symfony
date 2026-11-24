<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;

final class MoneyOverflow extends DomainError
{
    public function __construct()
    {
        parent::__construct('Integer overflow while adding money.');
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::MoneyOverflow;
    }
}
