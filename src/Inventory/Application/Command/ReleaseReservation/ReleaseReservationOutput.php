<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\ReleaseReservation;

/**
 * `released` is false only when the key is sold and therefore stays with the order.
 */
final readonly class ReleaseReservationOutput
{
    public function __construct(public bool $released) {}
}
