<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\DependencyInjection;

use App\Saga\Infrastructure\Temporal\Config\SagaConfiguration;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Validates the timeout table while the container is built (03_saga.md section 3, T-CFG-01): a configuration where the
 * reservation can expire before the saga steps end fails the build with `InvalidSagaConfiguration`.
 */
final class SagaConfigurationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $activities = $container->getParameter('temporal.activities');

        SagaConfiguration::fromParameters(
            self::string($container->getParameter('temporal.task_queue')),
            self::int($container->getParameter('temporal.order_workflow.execution_timeout_seconds')),
            $activities,
            self::int($container->getParameter('inventory.reservation_ttl_seconds')),
            self::int($container->getParameter('payment.charge_guard_seconds')),
        );
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : throw new \UnexpectedValueException('Saga parameter must be an integer.');
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : throw new \UnexpectedValueException('Saga parameter must be a string.');
    }
}
