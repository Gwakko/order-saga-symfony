<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\ConsumeReservation;

final readonly class ConsumeReservationCommand
{
    public function __construct(public string $orderId) {}
}
