<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;

final class InvalidIdentifier extends DomainError
{
    public function __construct(public readonly string $identifierClass, public readonly string $value)
    {
        parent::__construct(sprintf('Invalid UUID "%s" for %s.', $value, $identifierClass));
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::InvalidIdentifier;
    }
}
