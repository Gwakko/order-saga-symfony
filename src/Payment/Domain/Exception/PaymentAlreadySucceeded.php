<?php

declare(strict_types=1);

namespace App\Payment\Domain\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;
use App\Shared\Domain\Identifier\PaymentId;

final class PaymentAlreadySucceeded extends DomainError
{
    public function __construct(public readonly PaymentId $paymentId)
    {
        parent::__construct(sprintf('Payment %s already succeeded with another charge.', $paymentId->value));
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::PaymentAlreadySucceeded;
    }
}
