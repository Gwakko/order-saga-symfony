<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;

final class InvalidRefundWindow extends DomainError
{
    public function __construct(public readonly int $seconds)
    {
        parent::__construct(sprintf('Refund window must be positive, got %d seconds.', $seconds));
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::InvalidRefundWindow;
    }
}
