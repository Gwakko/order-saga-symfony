<?php

declare(strict_types=1);

use App\Kernel;
use App\Saga\Infrastructure\Temporal\Activity\InventoryActivitiesImpl;
use App\Saga\Infrastructure\Temporal\Activity\OrderActivitiesImpl;
use App\Saga\Infrastructure\Temporal\Activity\PaymentActivitiesImpl;
use App\Saga\Infrastructure\Temporal\Config\SagaConfiguration;
use App\Saga\Infrastructure\Temporal\Config\SagaConfigurationHolder;
use App\Saga\Infrastructure\Temporal\Workflow\OrderFulfillmentWorkflowImpl;
use App\Saga\Infrastructure\Temporal\Workflow\RefundWorkflowImpl;
use Symfony\Component\Dotenv\Dotenv;
use Temporal\Testing\WorkerFactory;

/**
 * Test worker started by RoadRunner (tests/Workflow/rr.test.yaml). The real workflow runs with the real timeout table
 * from config/packages/temporal.yaml; the activities are registered but every call is answered by the mocks that the
 * test stored through ActivityMocker (Temporal\Testing\WorkerFactory consults them first), so no database is touched.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');

$kernel = new Kernel('test', false);
$kernel->boot();
$configuration = $kernel->getContainer()->get(SagaConfiguration::class);
SagaConfigurationHolder::set($configuration);

$factory = WorkerFactory::create();
$worker = $factory->newWorker($configuration->taskQueue);
$worker->registerWorkflowTypes(OrderFulfillmentWorkflowImpl::class, RefundWorkflowImpl::class);

foreach ([InventoryActivitiesImpl::class, PaymentActivitiesImpl::class, OrderActivitiesImpl::class] as $activity) {
    $worker->registerActivity(
        $activity,
        static fn(ReflectionClass $class): object => throw new LogicException('Activity is not mocked: ' . $class->getName()),
    );
}

$factory->run();
