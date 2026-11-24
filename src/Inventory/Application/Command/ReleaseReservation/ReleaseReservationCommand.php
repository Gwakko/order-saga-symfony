<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\ReleaseReservation;

final readonly class ReleaseReservationCommand
{
    public function __construct(public string $orderId) {}
}
