<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Schedule;

use App\Shared\Application\Command\PurgeIdempotencyKeys\PurgeIdempotencyKeysCommand;
use App\Shared\Application\Command\PurgeIdempotencyKeys\PurgeIdempotencyKeysHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PurgeIdempotencyKeysTickHandler
{
    public function __construct(private PurgeIdempotencyKeysHandler $handler, private LoggerInterface $logger) {}

    public function __invoke(PurgeIdempotencyKeysTick $tick): void
    {
        $result = ($this->handler)(new PurgeIdempotencyKeysCommand($tick->limit));

        if ($result->deleted > 0) {
            $this->logger->info('Purged expired idempotency keys.', ['deleted' => $result->deleted]);
        }
    }
}
