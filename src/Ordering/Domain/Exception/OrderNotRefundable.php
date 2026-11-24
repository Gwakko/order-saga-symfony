<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;
use App\Ordering\Domain\OrderStatus;

final class OrderNotRefundable extends DomainError
{
    public function __construct(public readonly OrderStatus $status)
    {
        parent::__construct(sprintf('Order in status %s cannot be refunded.', $status->value));
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::OrderNotRefundable;
    }
}
