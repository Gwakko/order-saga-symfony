<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;

final class RefundWindowClosed extends DomainError
{
    public function __construct()
    {
        parent::__construct('Refund window is closed.');
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::RefundWindowClosed;
    }
}
