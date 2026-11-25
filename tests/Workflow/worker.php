<?php

declare(strict_types=1);

use App\Kernel;
use App\Saga\Infrastructure\Temporal\Activity\GatewayInspectionActivities;
use App\Saga\Infrastructure\Temporal\Activity\InventoryActivitiesImpl;
use App\Saga\Infrastructure\Temporal\Activity\OrderActivitiesImpl;
use App\Saga\Infrastructure\Temporal\Activity\PaymentActivitiesImpl;
use App\Saga\Infrastructure\Temporal\Config\ActivityProfile;
use App\Saga\Infrastructure\Temporal\Config\SagaConfiguration;
use App\Saga\Infrastructure\Temporal\Config\SagaConfigurationHolder;
use App\Saga\Infrastructure\Temporal\Workflow\OrderFulfillmentWorkflowImpl;
use App\Saga\Infrastructure\Temporal\Workflow\RefundWorkflowImpl;
use App\Tests\Workflow\Support\SequencedActivityInvocationCache;
use App\Tests\Workflow\Support\UnmockedGatewayInspection;
use Symfony\Component\Dotenv\Dotenv;
use Temporal\Testing\WorkerFactory;

/**
 * Test worker started by RoadRunner (tests/Workflow/rr.test.yaml). The real workflow runs with the real timeout table
 * from config/packages/temporal.yaml; the activities are registered but every call is answered by the mocks that the
 * test stored through ActivityMocker (Temporal\Testing\WorkerFactory consults them first), so no database is touched.
 * A second worker on the Go queue stands in for the Go worker of `InspectGatewayCharge` (docs/spec/09_go_worker.md
 * section 8); its calls are mocked the same way. The activity cache also understands per-call answer sequences.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');

$kernel = new Kernel('test', false);
$kernel->boot();
$configuration = $kernel->getContainer()->get(SagaConfiguration::class);
SagaConfigurationHolder::set($configuration);

$factory = WorkerFactory::create(activityCache: SequencedActivityInvocationCache::create());
$worker = $factory->newWorker($configuration->taskQueue);
$worker->registerWorkflowTypes(OrderFulfillmentWorkflowImpl::class, RefundWorkflowImpl::class);

foreach ([InventoryActivitiesImpl::class, PaymentActivitiesImpl::class, OrderActivitiesImpl::class] as $activity) {
    $worker->registerActivity(
        $activity,
        static fn(ReflectionClass $class): object => throw new LogicException('Activity is not mocked: ' . $class->getName()),
    );
}

$goQueue = $configuration->activities[ActivityProfile::InspectGatewayCharge->value]->taskQueue ?? throw new LogicException('The inspect_gateway_charge profile has no task queue.');
$goWorker = $factory->newWorker($goQueue);
$goWorker->registerActivity(
    UnmockedGatewayInspection::class,
    static fn(ReflectionClass $class): object => throw new LogicException('Activity is not mocked: ' . GatewayInspectionActivities::TYPE),
);

$factory->run();
