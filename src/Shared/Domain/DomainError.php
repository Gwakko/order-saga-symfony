<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Base class of every typed domain exception.
 */
abstract class DomainError extends \DomainException
{
    abstract public function code(): DomainErrorCode;
}
