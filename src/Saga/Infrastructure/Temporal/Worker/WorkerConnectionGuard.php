<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Worker;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * The worker is one long-lived PHP process, so its database connection outlives a Postgres restart. DBAL 4 has no
 * `ping()`; a connection is lazy, so the way to reconnect is `close()` and the next query opens a new one (M6.1).
 */
final readonly class WorkerConnectionGuard
{
    public function __construct(private Connection $connection, private LoggerInterface $logger) {}

    /**
     * Before an activity: a connection that has gone stale is dropped, so the activity starts on a fresh one. A failed
     * probe that is not a connection problem is a real error and is rethrown.
     */
    public function ensureAlive(): void
    {
        if (!$this->connection->isConnected()) {
            return;
        }

        try {
            $this->connection->executeQuery('SELECT 1')->free();
        } catch (\Throwable $failure) {
            if (!TransientDatabaseFailure::matches($failure)) {
                throw $failure;
            }

            $this->logger->warning('Worker database connection is stale, reconnecting.', ['error' => $failure->getMessage()]);
            $this->discard();
        }
    }

    /** Drops the connection (and with it any transaction state); the next use reconnects. */
    public function discard(): void
    {
        $this->connection->close();
    }

    /**
     * After an activity: a transaction left open would leak into the next activity, so the connection is dropped (the
     * server rolls the transaction back).
     */
    public function settle(): void
    {
        if ($this->connection->isConnected() && $this->connection->isTransactionActive()) {
            $this->logger->error('An activity left a transaction open, dropping the connection.');
            $this->discard();
        }
    }
}
