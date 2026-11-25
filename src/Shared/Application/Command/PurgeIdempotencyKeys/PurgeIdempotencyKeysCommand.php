<?php

declare(strict_types=1);

namespace App\Shared\Application\Command\PurgeIdempotencyKeys;

use App\Shared\Application\Exception\InvalidInput;

final readonly class PurgeIdempotencyKeysCommand
{
    public const int MAX_LIMIT = 100_000;

    public function __construct(public int $limit = 10_000)
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidInput('limit', sprintf('must be 1..%d, got %d', self::MAX_LIMIT, $limit));
        }
    }
}
