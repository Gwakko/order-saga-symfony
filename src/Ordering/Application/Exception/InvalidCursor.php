<?php

declare(strict_types=1);

namespace App\Ordering\Application\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;

final class InvalidCursor extends DomainError
{
    public function __construct()
    {
        parent::__construct('The pagination cursor is malformed.');
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::InvalidCursor;
    }
}
