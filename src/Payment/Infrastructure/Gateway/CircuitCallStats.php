<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Gateway;

/**
 * What the store knows after a recorded call: the current failure streak and the calls inside the window.
 */
final readonly class CircuitCallStats
{
    public function __construct(public int $consecutiveFailures, public int $calls, public int $failures) {}
}
