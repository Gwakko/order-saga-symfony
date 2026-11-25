<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\SweepStuckOrders;

final readonly class SweepStuckOrdersOutput
{
    public function __construct(
        public int $checked,
        public int $interrupted,
        public int $stillRunning,
        public int $skipped,
    ) {}
}
