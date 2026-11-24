<?php

declare(strict_types=1);

use App\Kernel;
use App\Saga\Infrastructure\Temporal\Activity\InventoryActivitiesImpl;
use App\Saga\Infrastructure\Temporal\Activity\OrderActivitiesImpl;
use App\Saga\Infrastructure\Temporal\Activity\PaymentActivitiesImpl;
use App\Saga\Infrastructure\Temporal\Config\SagaConfiguration;
use App\Saga\Infrastructure\Temporal\Config\SagaConfigurationHolder;
use App\Saga\Infrastructure\Temporal\Hello\HelloActivityImpl;
use App\Saga\Infrastructure\Temporal\Hello\HelloWorkflowImpl;
use App\Saga\Infrastructure\Temporal\Worker\ActivityLifecycle;
use App\Saga\Infrastructure\Temporal\Worker\ActivityResilienceInterceptor;
use App\Saga\Infrastructure\Temporal\Workflow\OrderFulfillmentWorkflowImpl;
use App\Saga\Infrastructure\Temporal\Workflow\RefundWorkflowImpl;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Temporal\Interceptor\SimplePipelineProvider;
use Temporal\WorkerFactory;

/**
 * RoadRunner entry point (`.rr.yaml` -> `server.command`). RoadRunner owns the gRPC connection to Temporal and starts
 * this script as a long-lived PHP process; here we boot the Symfony Kernel once and serve workflows and activities.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');

$kernel = new Kernel((string) $_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();
/** @var ContainerInterface $container */
$container = $kernel->getContainer();

$configuration = $container->get(SagaConfiguration::class);
if (!$configuration instanceof SagaConfiguration) {
    throw new LogicException('SagaConfiguration service is missing');
}
SagaConfigurationHolder::set($configuration);

$interceptor = $container->get(ActivityResilienceInterceptor::class);
$lifecycle = $container->get(ActivityLifecycle::class);
if (!$interceptor instanceof ActivityResilienceInterceptor || !$lifecycle instanceof ActivityLifecycle) {
    throw new LogicException('Worker resilience services are missing');
}

$factory = WorkerFactory::create();
// The interceptor checks the database connection before every activity and marks transient database failures
// retryable (EC-85); the finalizer below runs after every activity.
$worker = $factory->newWorker($configuration->taskQueue, interceptorProvider: new SimplePipelineProvider([$interceptor]));

$worker->registerWorkflowTypes(HelloWorkflowImpl::class, OrderFulfillmentWorkflowImpl::class, RefundWorkflowImpl::class);

// Activities are registered by their implementation class and fetched from the container (public services).
foreach ([HelloActivityImpl::class, InventoryActivitiesImpl::class, PaymentActivitiesImpl::class, OrderActivitiesImpl::class] as $activity) {
    $worker->registerActivity(
        $activity,
        static fn (ReflectionClass $class): object => $container->get($class->getName()) ?? throw new LogicException('Activity service is missing: ' . $class->getName()),
    );
}
// After every activity: drop a connection with an open transaction, reset stateful services (Monolog buffers etc.).
$worker->registerActivityFinalizer(static fn () => $lifecycle->finish());

$factory->run();
