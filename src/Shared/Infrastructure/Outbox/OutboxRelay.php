<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Infrastructure\Persistence\DbalTransactionManager;
use App\Shared\Infrastructure\Persistence\DbTimestamp;
use App\Shared\Infrastructure\Persistence\Row;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Publishes unpublished outbox rows, at least once (00_overview.md section 7.5).
 *
 * A batch is selected `FOR UPDATE SKIP LOCKED`, so parallel relays take disjoint rows (EC-56). A row is marked
 * published only after the transport accepted it: a crash in between leaves it unpublished and it is sent again
 * (EC-52); consumers deduplicate through the inbox. When the transport rejects a row the rows before it are still
 * marked, the failing one gets `attempts + 1`, the rest of the batch waits, and the error is rethrown.
 */
final readonly class OutboxRelay
{
    public function __construct(
        private Connection $connection,
        private DbalTransactionManager $transactions,
        private OutboxPublisher $publisher,
        private ClockInterface $clock,
    ) {}

    /**
     * @return int number of rows published
     */
    public function publishBatch(int $limit = 100): int
    {
        /** @var array{0: int, 1: ?\Throwable} $outcome */
        $outcome = $this->transactions->transactional(fn(): array => $this->relay($limit));

        if ($outcome[1] !== null) {
            throw $outcome[1];
        }

        return $outcome[0];
    }

    /**
     * @return array{0: int, 1: ?\Throwable}
     */
    private function relay(int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, aggregate_type, aggregate_id, aggregate_version, event_type, payload, occurred_at
             FROM outbox_messages
             WHERE published_at IS NULL
             ORDER BY occurred_at, aggregate_version, id
             LIMIT :limit
             FOR UPDATE SKIP LOCKED',
            ['limit' => $limit],
        );

        $published = 0;

        foreach ($rows as $raw) {
            $row = new Row($raw);

            try {
                $this->publisher->publish($this->toMessage($row));
            } catch (\Throwable $e) {
                $this->connection->executeStatement(
                    'UPDATE outbox_messages SET attempts = attempts + 1 WHERE id = :id',
                    ['id' => $row->string('id')],
                );

                return [$published, $e];
            }

            $this->connection->executeStatement(
                'UPDATE outbox_messages SET published_at = :now, attempts = attempts + 1 WHERE id = :id',
                ['id' => $row->string('id'), 'now' => DbTimestamp::format(\DateTimeImmutable::createFromInterface($this->clock->now()))],
            );
            ++$published;
        }

        return [$published, null];
    }

    private function toMessage(Row $row): OutboxMessage
    {
        $payload = json_decode($row->string('payload'), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($payload)) {
            throw new \UnexpectedValueException('Outbox payload is not a JSON object.');
        }

        /** @var array<string, mixed> $payload */
        return new OutboxMessage(
            $row->string('id'),
            $row->string('aggregate_type'),
            $row->string('aggregate_id'),
            $row->int('aggregate_version'),
            $row->string('event_type'),
            $payload,
            DbTimestamp::format($row->time('occurred_at')),
        );
    }
}
