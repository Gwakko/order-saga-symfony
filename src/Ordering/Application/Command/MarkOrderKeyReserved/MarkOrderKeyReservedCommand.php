<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\MarkOrderKeyReserved;

final readonly class MarkOrderKeyReservedCommand
{
    public function __construct(public string $orderId, public string $reservationId) {}
}
