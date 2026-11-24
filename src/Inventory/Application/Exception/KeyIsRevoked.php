<?php

declare(strict_types=1);

namespace App\Inventory\Application\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;
use App\Shared\Domain\Identifier\ProductKeyId;

/**
 * The supplier revoked the key while it was reserved (EC-18).
 */
final class KeyIsRevoked extends DomainError
{
    public function __construct(public readonly ProductKeyId $keyId)
    {
        parent::__construct(sprintf('Key %s was revoked.', $keyId->value));
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::KeyRevoked;
    }
}
