<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence;

use App\Shared\Application\Port\TransactionManager;
use Doctrine\DBAL\Connection;

/**
 * Re-entrant: inside an open transaction the callback just runs, so a repository may call it for its own atomic
 * write (state + outbox) whether or not a use case already opened a transaction. The outermost call commits or rolls
 * back (no savepoints: a failed inner step rolls back the whole unit, as `TransactionManager` promises).
 */
final readonly class DbalTransactionManager implements TransactionManager
{
    public function __construct(private Connection $connection) {}

    public function transactional(callable $callback): mixed
    {
        if ($this->connection->isTransactionActive()) {
            return $callback();
        }

        $this->connection->beginTransaction();

        try {
            $result = $callback();
            $this->connection->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }
    }
}
