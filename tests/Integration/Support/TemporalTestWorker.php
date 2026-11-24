<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use Symfony\Component\Process\Process;

/**
 * The real worker (`bin/temporal-worker.php` under RoadRunner) in the `test` environment: it polls the test task queue
 * of the dev Temporal server of the compose stack and runs the real workflows and activities against `app_test` and the
 * gateway emulator. The compose `temporal-worker` serves another queue (`order-saga`), so the two never meet.
 */
final class TemporalTestWorker
{
    private ?Process $process = null;

    public function start(string $temporalAddress): void
    {
        $root = dirname(__DIR__, 3);

        $this->process = new Process(
            ['rr', 'serve', '-c', $root . '/.rr.yaml', '-o', 'rpc.listen=tcp://127.0.0.1:6002', '-o', 'logs.level=error'],
            $root,
            ['APP_ENV' => 'test', 'APP_DEBUG' => '0', 'TEMPORAL_ADDRESS' => $temporalAddress],
        );
        $this->process->start();

        usleep(1_500_000);

        if (!$this->process->isRunning()) {
            throw new \RuntimeException('The test worker exited early: ' . $this->process->getOutput() . $this->process->getErrorOutput());
        }
    }

    public function stop(): void
    {
        $this->process?->stop(5);
        $this->process = null;
    }
}
