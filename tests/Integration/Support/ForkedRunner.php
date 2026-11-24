<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * Runs closures in forked processes, each with its own database connection, and releases them at the same moment:
 * every child connects, reports "ready" through a file, then waits for the parent's "go" file.
 *
 * The child ends with SIGKILL: a normal exit would run the destructors of the sockets inherited from the parent and
 * could terminate the parent's database session.
 */
final class ForkedRunner
{
    private const int WAIT_STEP_US = 200;
    private const int MAX_STEPS = 100_000;

    /**
     * @param list<\Closure(Connection): string> $jobs
     *
     * @return list<string> what each job returned ("EXCEPTION <class>: <message>" when it threw)
     */
    public static function run(Connection $parentConnection, array $jobs): array
    {
        $params = $parentConnection->getParams();
        $dir = sys_get_temp_dir() . '/fork-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $pids = [];

        foreach ($jobs as $index => $job) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new \RuntimeException('pcntl_fork failed');
            }

            if ($pid === 0) {
                self::child($params, $job, $dir, $index);
            }

            $pids[] = $pid;
        }

        self::waitUntil(static fn(): bool => count(self::files($dir . '/ready-*')) === count($jobs), 'children did not become ready');
        touch($dir . '/go');

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $results = [];

        foreach (array_keys($jobs) as $index) {
            $file = sprintf('%s/result-%d', $dir, $index);
            $results[] = is_file($file) ? (string) file_get_contents($file) : 'EXCEPTION child died without a result';
        }

        array_map(unlink(...), self::files($dir . '/*'));
        rmdir($dir);

        return $results;
    }

    /**
     * @param array<string, mixed> $params
     * @param \Closure(Connection): string $job
     */
    private static function child(array $params, \Closure $job, string $dir, int $index): never
    {
        try {
            /** @phpstan-ignore argument.type */
            $connection = DriverManager::getConnection($params);
            $connection->executeQuery('SELECT 1');
            touch(sprintf('%s/ready-%d', $dir, $index));

            self::waitUntil(static fn(): bool => file_exists($dir . '/go'), 'no go signal');

            $result = $job($connection);
        } catch (\Throwable $e) {
            $result = sprintf('EXCEPTION %s: %s', $e::class, $e->getMessage());
        }

        file_put_contents(sprintf('%s/result-%d', $dir, $index), $result);
        posix_kill(posix_getpid(), \SIGKILL);

        exit(1);
    }

    /**
     * @param \Closure(): bool $condition
     */
    private static function waitUntil(\Closure $condition, string $failure): void
    {
        for ($step = 0; $step < self::MAX_STEPS; ++$step) {
            clearstatcache();

            if ($condition()) {
                return;
            }

            usleep(self::WAIT_STEP_US);
        }

        throw new \RuntimeException($failure);
    }

    /**
     * @return list<string>
     */
    private static function files(string $pattern): array
    {
        $files = glob($pattern);

        return $files === false ? [] : $files;
    }
}
