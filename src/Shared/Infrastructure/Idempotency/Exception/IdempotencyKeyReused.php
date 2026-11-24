<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Idempotency\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;

final class IdempotencyKeyReused extends DomainError
{
    public function __construct()
    {
        parent::__construct('The Idempotency-Key was already used with a different request.');
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::IdempotencyKeyReused;
    }
}
