<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use App\Shared\Infrastructure\Outbox\OutboxMessage;
use App\Shared\Infrastructure\Outbox\OutboxPublisher;

/**
 * Remembers what it was asked to send. `crashAfterSending` models a relay that dies after the transport accepted a
 * message but before the row is marked (EC-52); `failBefore` models a transport that rejects a message.
 */
final class RecordingOutboxPublisher implements OutboxPublisher
{
    /** @var list<OutboxMessage> */
    public array $sent = [];

    public ?string $crashAfterSending = null;

    public ?string $failBefore = null;

    public function publish(OutboxMessage $message): void
    {
        if ($message->messageId === $this->failBefore) {
            throw new \RuntimeException('transport rejected the message');
        }

        $this->sent[] = $message;

        if ($message->messageId === $this->crashAfterSending) {
            throw new \RuntimeException('relay crashed after sending');
        }
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_map(static fn(OutboxMessage $m): string => $m->messageId, $this->sent);
    }
}
