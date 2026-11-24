<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Idempotency\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;

final class IdempotentRequestInProgress extends DomainError
{
    public function __construct()
    {
        parent::__construct('A request with this Idempotency-Key is still being processed.');
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::IdempotentRequestInProgress;
    }
}
