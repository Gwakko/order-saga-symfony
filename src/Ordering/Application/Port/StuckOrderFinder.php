<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

/**
 * Orders in an intermediate status (Pending, KeyReserved, Paid, Compensating, Refunding) whose last change is older
 * than `$updatedBefore`: candidates for the sweeper, oldest first.
 */
interface StuckOrderFinder
{
    /**
     * @return list<StuckOrder>
     */
    public function findUpdatedBefore(\DateTimeImmutable $updatedBefore, int $limit): array;
}
