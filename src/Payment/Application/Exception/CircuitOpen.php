<?php

declare(strict_types=1);

namespace App\Payment\Application\Exception;

use App\Shared\Application\Exception\RetryableFailure;
use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;

final class CircuitOpen extends DomainError implements RetryableFailure
{
    public function __construct(string $message = 'The payment circuit breaker is open.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::CircuitOpen;
    }
}
