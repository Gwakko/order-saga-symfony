<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;

final class InvalidKeyCode extends DomainError
{
    public function __construct()
    {
        parent::__construct('Key code must be 5..64 characters of [A-Z0-9-].');
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::InvalidKeyCode;
    }
}
