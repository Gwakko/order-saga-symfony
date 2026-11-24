<?php

declare(strict_types=1);

use App\Kernel;
use App\Saga\Infrastructure\Temporal\Hello\HelloActivityImpl;
use App\Saga\Infrastructure\Temporal\Hello\HelloWorkflowImpl;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\DependencyInjection\ContainerInterface;
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

$factory = WorkerFactory::create();
$worker = $factory->newWorker('order-saga');

$worker->registerWorkflowTypes(HelloWorkflowImpl::class);
$worker->registerActivity(
    HelloActivityImpl::class,
    static fn (ReflectionClass $class): object => $container->get(HelloActivityImpl::class) ?? throw new LogicException('Activity service is missing'),
);
// Reset stateful services (Monolog buffers, Doctrine-like caches) after every activity.
$worker->registerActivityFinalizer(static function () use ($container): void {
    $resetter = $container->get('services_resetter');
    if (is_object($resetter) && method_exists($resetter, 'reset')) {
        $resetter->reset();
    }
});

$factory->run();
