<?php

declare(strict_types=1);

namespace App\Ordering\Application\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;
use App\Shared\Domain\Identifier\ProductKeyId;

final class DeliveredKeyNotFound extends DomainError
{
    public function __construct(public readonly ProductKeyId $keyId)
    {
        parent::__construct(sprintf('Key %s to deliver was not found.', $keyId->value));
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::InvariantViolated;
    }
}
