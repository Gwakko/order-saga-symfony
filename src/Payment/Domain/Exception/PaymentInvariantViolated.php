<?php

declare(strict_types=1);

namespace App\Payment\Domain\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;

final class PaymentInvariantViolated extends DomainError
{
    public function __construct(string $detail)
    {
        parent::__construct('Payment invariant violated: ' . $detail);
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::InvariantViolated;
    }
}
