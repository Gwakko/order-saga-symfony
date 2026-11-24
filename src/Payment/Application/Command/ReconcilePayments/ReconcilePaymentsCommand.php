<?php

declare(strict_types=1);

namespace App\Payment\Application\Command\ReconcilePayments;

use App\Shared\Application\Exception\InvalidInput;

final readonly class ReconcilePaymentsCommand
{
    public const int MIN_AGE_SECONDS = 60;
    public const int MAX_LIMIT = 1000;

    public function __construct(public int $olderThanSeconds, public int $limit)
    {
        if ($olderThanSeconds < self::MIN_AGE_SECONDS) {
            throw new InvalidInput('olderThanSeconds', sprintf('must be at least %d, got %d', self::MIN_AGE_SECONDS, $olderThanSeconds));
        }

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidInput('limit', sprintf('must be 1..%d, got %d', self::MAX_LIMIT, $limit));
        }
    }
}
