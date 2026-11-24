<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;
use App\Inventory\Domain\KeyStatus;

final class IllegalKeyTransition extends DomainError
{
    public function __construct(public readonly KeyStatus $from, public readonly KeyStatus $to)
    {
        parent::__construct(sprintf('Key cannot go from %s to %s.', $from->value, $to->value));
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::IllegalKeyTransition;
    }
}
