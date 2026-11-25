<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Activity;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

/**
 * The activity that the Go worker serves (docs/spec/09_go_worker.md section 4.1). There is no PHP implementation and
 * `bin/temporal-worker.php` does not register one: the interface only gives the workflow a typed stub with the activity
 * name. The test worker registers a stand-in (tests/Workflow/Support) so the queue has a poller; every call is mocked.
 */
#[ActivityInterface]
interface GatewayInspectionActivities
{
    public const string TYPE = 'InspectGatewayCharge';

    /**
     * @param array<string, mixed> $input `InspectionRequest::toArray()`
     *
     * @return array<mixed> the raw `InspectionResult` (parsed by `InspectionResult::fromArray`)
     */
    #[ActivityMethod(name: 'InspectGatewayCharge')]
    public function inspectGatewayCharge(array $input): array;
}
