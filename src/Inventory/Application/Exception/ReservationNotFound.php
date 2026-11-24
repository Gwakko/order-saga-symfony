<?php

declare(strict_types=1);

namespace App\Inventory\Application\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;
use App\Shared\Domain\Identifier\OrderId;

final class ReservationNotFound extends DomainError
{
    public function __construct(public readonly OrderId $orderId)
    {
        parent::__construct(sprintf('No reservation for order %s.', $orderId->value));
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::InvariantViolated;
    }
}
