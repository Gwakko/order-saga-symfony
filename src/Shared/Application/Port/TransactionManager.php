<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

interface TransactionManager
{
    /**
     * Runs the callback in one database transaction: commit on return, rollback on any exception.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function transactional(callable $callback): mixed;
}
