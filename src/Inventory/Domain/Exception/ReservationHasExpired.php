<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;
use App\Shared\Domain\Identifier\ReservationId;

final class ReservationHasExpired extends DomainError
{
    public function __construct(public readonly ReservationId $reservationId)
    {
        parent::__construct(sprintf('Reservation %s has expired.', $reservationId->value));
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::ReservationExpired;
    }
}
