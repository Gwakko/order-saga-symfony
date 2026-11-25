<?php

declare(strict_types=1);

namespace App\Shared\Application\Command\PurgeIdempotencyKeys;

use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Port\IdempotencyKeyCleaner;
use Psr\Clock\ClockInterface;

/**
 * Removes expired idempotency rows (completed answers past 24 h and abandoned `in_progress` rows alike). Safe to run
 * at any time and to repeat: a row that is not past `expires_at` is never touched.
 */
final readonly class PurgeIdempotencyKeysHandler implements CommandHandler
{
    public function __construct(private IdempotencyKeyCleaner $cleaner, private ClockInterface $clock) {}

    public function __invoke(PurgeIdempotencyKeysCommand $command): PurgeIdempotencyKeysOutput
    {
        return new PurgeIdempotencyKeysOutput(
            $this->cleaner->deleteExpired(\DateTimeImmutable::createFromInterface($this->clock->now()), $command->limit),
        );
    }
}
