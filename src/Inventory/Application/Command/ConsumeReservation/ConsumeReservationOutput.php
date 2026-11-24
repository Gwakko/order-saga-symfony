<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\ConsumeReservation;

final readonly class ConsumeReservationOutput
{
    public function __construct(public string $keyId) {}
}
