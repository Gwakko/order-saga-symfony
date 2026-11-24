<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;
use App\Ordering\Domain\OrderStatus;

final class IllegalOrderTransition extends DomainError
{
    public function __construct(public readonly OrderStatus $from, public readonly OrderStatus $to)
    {
        parent::__construct(sprintf('Order cannot go from %s to %s.', $from->value, $to->value));
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::IllegalOrderTransition;
    }
}
