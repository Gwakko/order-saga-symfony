<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\ExpireReservations;

use App\Shared\Application\Exception\InvalidInput;

final readonly class ExpireReservationsCommand
{
    public const int MAX_LIMIT = 1000;

    public function __construct(public int $limit)
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidInput('limit', sprintf('must be 1..%d, got %d', self::MAX_LIMIT, $limit));
        }
    }
}
