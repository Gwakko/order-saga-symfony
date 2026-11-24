<?php

declare(strict_types=1);

namespace App\Payment\Domain\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;
use App\Payment\Domain\PaymentStatus;

final class IllegalPaymentTransition extends DomainError
{
    public function __construct(public readonly PaymentStatus $from, public readonly PaymentStatus $to)
    {
        parent::__construct(sprintf('Payment cannot go from %s to %s.', $from->value, $to->value));
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::IllegalPaymentTransition;
    }
}
