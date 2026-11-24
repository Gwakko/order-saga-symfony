<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;
use App\Inventory\Domain\ReservationStatus;

final class IllegalReservationTransition extends DomainError
{
    public function __construct(public readonly ReservationStatus $from, public readonly ReservationStatus $to)
    {
        parent::__construct(sprintf('Reservation cannot go from %s to %s.', $from->value, $to->value));
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::IllegalReservationTransition;
    }
}
