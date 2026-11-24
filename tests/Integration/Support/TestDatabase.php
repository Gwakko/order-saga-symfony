<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Creates the `*_test` database when it is missing and migrates it to the latest version, once per PHPUnit process.
 */
final class TestDatabase
{
    private static bool $prepared = false;

    private const array TABLES = [
        'orders', 'order_view', 'products', 'product_keys', 'key_reservations', 'product_availability_view',
        'payments', 'outbox_messages', 'inbox_messages', 'idempotency_keys',
    ];

    public static function prepare(KernelInterface $kernel, Connection $connection): void
    {
        if (self::$prepared) {
            return;
        }

        $params = $connection->getParams();
        $name = $params['dbname'] ?? throw new \LogicException('The test connection has no database name.');
        $admin = DriverManager::getConnection([...$params, 'dbname' => 'postgres']);

        if ($admin->fetchOne('SELECT 1 FROM pg_database WHERE datname = :name', ['name' => $name]) === false) {
            $admin->executeStatement(sprintf('CREATE DATABASE "%s"', $name));
        }

        $admin->close();

        $application = new Application($kernel);
        $application->setAutoExit(false);
        $exit = $application->run(
            new ArrayInput(['command' => 'doctrine:migrations:migrate', '--no-interaction' => true, '--allow-no-migration' => true]),
            new NullOutput(),
        );

        if ($exit !== 0) {
            throw new \RuntimeException('Test database migration failed.');
        }

        self::$prepared = true;
    }

    public static function truncate(Connection $connection): void
    {
        $connection->executeStatement('TRUNCATE ' . implode(', ', self::TABLES));
    }
}
