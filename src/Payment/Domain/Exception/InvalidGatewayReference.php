<?php

declare(strict_types=1);

namespace App\Payment\Domain\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;

final class InvalidGatewayReference extends DomainError
{
    public function __construct(string $what)
    {
        parent::__construct(sprintf('Gateway %s must not be empty.', $what));
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::InvalidGatewayReference;
    }
}
