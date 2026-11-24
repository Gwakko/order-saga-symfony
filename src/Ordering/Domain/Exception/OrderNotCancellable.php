<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;
use App\Ordering\Domain\OrderStatus;

final class OrderNotCancellable extends DomainError
{
    public function __construct(public readonly OrderStatus $status)
    {
        parent::__construct(sprintf('Order in status %s cannot be cancelled.', $status->value));
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::OrderNotCancellable;
    }
}
