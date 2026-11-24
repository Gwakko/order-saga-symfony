<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Idempotency\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;

final class IdempotencyKeyInvalid extends DomainError
{
    public function __construct()
    {
        parent::__construct('The Idempotency-Key must be 1..255 characters of [A-Za-z0-9_-].');
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::IdempotencyKeyInvalid;
    }
}
