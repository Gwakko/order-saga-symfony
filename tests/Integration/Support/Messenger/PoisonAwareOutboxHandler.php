<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support\Messenger;

use App\Shared\Infrastructure\Outbox\OutboxMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Test consumer: records what it handled; every message of event type `Poison` fails (EC-57).
 */
#[AsMessageHandler]
final class PoisonAwareOutboxHandler
{
    /** @var list<string> */
    public static array $handled = [];

    public static int $poisonAttempts = 0;

    public function __invoke(OutboxMessage $message): void
    {
        if ($message->eventType === 'Poison') {
            ++self::$poisonAttempts;

            throw new \RuntimeException('poison message');
        }

        self::$handled[] = $message->messageId;
    }
}
