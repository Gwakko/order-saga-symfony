<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Config;

/**
 * The SDK instantiates workflow classes itself (no constructor injection), so the worker bootstrap hands the
 * validated configuration over through this holder once, before the first workflow task. Immutable value, set once
 * per process; workflow code only reads it.
 */
final class SagaConfigurationHolder
{
    private static ?SagaConfiguration $configuration = null;

    public static function set(SagaConfiguration $configuration): void
    {
        self::$configuration = $configuration;
    }

    public static function get(): SagaConfiguration
    {
        return self::$configuration ?? throw new \LogicException('SagaConfiguration is not set: the worker bootstrap must call SagaConfigurationHolder::set().');
    }
}
