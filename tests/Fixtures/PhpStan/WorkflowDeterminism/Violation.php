<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\PhpStan\WorkflowDeterminism;

use Doctrine\DBAL\Connection;
use Temporal\Workflow\WorkflowInterface;

#[WorkflowInterface]
final class Violation
{
    private static int $counter = 0;

    public function run(Connection $connection): void
    {
        time();
        microtime(true);
        new \DateTimeImmutable();
        new \DateTime('now');
        random_int(1, 10);
        rand();
        uniqid();
        sleep(1);
        usleep(10);
        getenv('HOME');
        file_get_contents('/etc/hosts');
        \Ramsey\Uuid\Uuid::uuid4();
        self::$counter++;
        $connection->close();
        new \PDO('sqlite::memory:');
    }
}
