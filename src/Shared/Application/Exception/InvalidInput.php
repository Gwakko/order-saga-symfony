<?php

declare(strict_types=1);

namespace App\Shared\Application\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;

/**
 * An Input DTO was built with a value outside its documented range (for example `limit` 0).
 */
final class InvalidInput extends DomainError
{
    public function __construct(public readonly string $field, string $rule)
    {
        parent::__construct(sprintf('Invalid %s: %s.', $field, $rule));
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::ValidationFailed;
    }
}
