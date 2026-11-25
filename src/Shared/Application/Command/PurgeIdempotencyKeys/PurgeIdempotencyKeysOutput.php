<?php

declare(strict_types=1);

namespace App\Shared\Application\Command\PurgeIdempotencyKeys;

final readonly class PurgeIdempotencyKeysOutput
{
    public function __construct(public int $deleted) {}
}
