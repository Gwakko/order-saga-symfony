<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;

/**
 * The `X-Customer-Id` header is absent or is not a UUID (EC-71).
 */
final class CustomerRequired extends DomainError
{
    public function __construct()
    {
        parent::__construct('A valid X-Customer-Id header (UUID) is required.');
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::CustomerRequired;
    }
}
