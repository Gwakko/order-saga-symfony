<?php

declare(strict_types=1);

namespace App\Tests\Workflow\Support;

use App\Saga\Infrastructure\Temporal\Activity\GatewayInspectionActivities;

/**
 * Stand-in for the Go worker on the test queue: it exists so that the queue has a poller. Every call must be answered by
 * a mock; a call that reaches this class means the test forgot one.
 */
final class UnmockedGatewayInspection implements GatewayInspectionActivities
{
    public function inspectGatewayCharge(array $input): array
    {
        throw new \LogicException('InspectGatewayCharge is not mocked.');
    }
}
