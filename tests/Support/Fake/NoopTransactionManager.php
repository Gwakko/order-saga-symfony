<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Shared\Application\Port\TransactionManager;

final class NoopTransactionManager implements TransactionManager
{
    public int $transactions = 0;

    public function transactional(callable $callback): mixed
    {
        ++$this->transactions;

        return $callback();
    }
}
