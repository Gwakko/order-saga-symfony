<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use App\Shared\Infrastructure\Outbox\OutboxMessage;
use App\Shared\Infrastructure\Outbox\OutboxPublisher;

/**
 * Appends sent message ids to a file shared by forked relays (one line per send, atomic append).
 */
final readonly class FileOutboxPublisher implements OutboxPublisher
{
    public function __construct(private string $file, private int $pauseMicroseconds = 0) {}

    public function publish(OutboxMessage $message): void
    {
        file_put_contents($this->file, $message->messageId . "\n", FILE_APPEND | LOCK_EX);

        if ($this->pauseMicroseconds > 0) {
            usleep($this->pauseMicroseconds);
        }
    }
}
