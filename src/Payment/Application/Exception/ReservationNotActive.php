<?php

declare(strict_types=1);

namespace App\Payment\Application\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;
use App\Shared\Domain\Identifier\OrderId;

/**
 * Pre-check of `ChargePayment` (EC-17): the key hold is gone or ends too soon to charge safely.
 */
final class ReservationNotActive extends DomainError
{
    public function __construct(public readonly OrderId $orderId)
    {
        parent::__construct(sprintf('Reservation of order %s is not active long enough to charge.', $orderId->value));
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::ReservationExpired;
    }
}
