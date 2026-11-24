<?php

declare(strict_types=1);

namespace App\Tests\Workflow;

use Symfony\Component\Process\Process;
use Temporal\Testing\TemporalServer;

/**
 * Starts, once per PHPUnit process, what the workflow tests need: `temporal-test-server` (time skipping) on
 * 127.0.0.1:7233 and RoadRunner running tests/Workflow/worker.php against it. Neither is downloaded here: the test
 * server binary is installed explicitly with `make wf-install` (bin/install-temporal-test-server.php), RoadRunner
 * is the binary of the php image.
 */
final class WorkflowTestEnvironment
{
    public const string ADDRESS = '127.0.0.1:7233';

    private static bool $started = false;
    private static ?Process $server = null;
    private static ?Process $roadRunner = null;

    public static function ensureStarted(): void
    {
        if (self::$started) {
            return;
        }

        $root = dirname(__DIR__, 2);
        $binary = $root . '/var/temporal-test-server/temporal-test-server';

        if (!is_file($binary)) {
            throw new \RuntimeException('temporal-test-server is not installed. Run `make wf-install` once (downloads a pinned release into var/temporal-test-server/).');
        }

        TemporalServer::setAddress(self::ADDRESS);

        self::$server = new Process([$binary, '7233', '--enable-time-skipping'], $root);
        self::$server->start();
        self::waitForPort('127.0.0.1', 7233, 'temporal-test-server', self::$server);

        self::$roadRunner = new Process(['rr', 'serve', '-c', __DIR__ . '/rr.test.yaml'], __DIR__, ['APP_ENV' => 'test']);
        self::$roadRunner->start();
        self::waitForPort('127.0.0.1', 6001, 'RoadRunner', self::$roadRunner);
        self::waitForWorker();

        register_shutdown_function(static function (): void {
            self::$roadRunner?->stop(5);
            self::$server?->stop(5);
        });

        self::$started = true;
    }

    private static function waitForPort(string $host, int $port, string $name, Process $process): void
    {
        for ($attempt = 0; $attempt < 300; ++$attempt) { // 30 s
            if (!$process->isRunning()) {
                throw new \RuntimeException(sprintf('%s exited early: %s %s', $name, $process->getOutput(), $process->getErrorOutput()));
            }

            $socket = @fsockopen($host, $port, $errno, $error, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep(100_000);
        }

        throw new \RuntimeException(sprintf('%s did not open %s:%d within 30 s.', $name, $host, $port));
    }

    /** RoadRunner opens its RPC port before the PHP worker has registered the workflow types. */
    private static function waitForWorker(): void
    {
        for ($attempt = 0; $attempt < 100; ++$attempt) { // about 30 s with the 3 s command timeout
            $check = new Process(['rr', 'workers', '-c', __DIR__ . '/rr.test.yaml'], __DIR__);
            $check->setTimeout(3);
            $check->run();

            if (str_contains($check->getOutput(), 'Workers of')) {
                return;
            }

            usleep(200_000);
        }

        throw new \RuntimeException(sprintf('The RoadRunner test worker did not start: %s', self::$roadRunner?->getOutput() . self::$roadRunner?->getErrorOutput()));
    }
}
