<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\ExpireReservations;

final readonly class ExpireReservationsOutput
{
    public function __construct(public int $expiredCount) {}
}
