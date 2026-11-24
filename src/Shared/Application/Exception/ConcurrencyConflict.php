<?php

declare(strict_types=1);

namespace App\Shared\Application\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;

/**
 * A repository refused a write because the aggregate version changed meanwhile (INV-15).
 */
final class ConcurrencyConflict extends DomainError implements RetryableFailure
{
    public function __construct(public readonly string $aggregate, public readonly string $aggregateId)
    {
        parent::__construct(sprintf('Concurrent modification of %s %s.', $aggregate, $aggregateId));
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::ConcurrencyConflict;
    }
}
