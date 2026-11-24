<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Inbox;

use App\Shared\Infrastructure\Persistence\DbalTransactionManager;
use App\Shared\Infrastructure\Persistence\DbTimestamp;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Consumer-side deduplication (INV-11). The handler's effects and the `inbox_messages` row commit in one
 * transaction, so a message is applied at most once per consumer; a repeat is acknowledged without effect (EC-53).
 * `consumer` names the handler, not the transport: two handlers of one message do not shadow each other.
 */
final readonly class InboxGuard
{
    public function __construct(
        private Connection $connection,
        private DbalTransactionManager $transactions,
        private ClockInterface $clock,
    ) {}

    /**
     * @param callable(): void $handler
     *
     * @return bool true when the handler ran, false for a duplicate
     */
    public function once(string $consumer, string $messageId, callable $handler): bool
    {
        return $this->transactions->transactional(function () use ($consumer, $messageId, $handler): bool {
            $inserted = $this->connection->executeStatement(
                'INSERT INTO inbox_messages (consumer, message_id, processed_at) VALUES (:consumer, :message_id, :now)
                 ON CONFLICT (consumer, message_id) DO NOTHING',
                [
                    'consumer' => $consumer,
                    'message_id' => $messageId,
                    'now' => DbTimestamp::format(\DateTimeImmutable::createFromInterface($this->clock->now())),
                ],
            );

            if ($inserted === 0) {
                return false;
            }

            $handler();

            return true;
        });
    }
}
