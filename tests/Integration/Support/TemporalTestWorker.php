<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use App\Saga\Infrastructure\Temporal\TemporalClientFactory;
use Symfony\Component\Process\Process;
use Temporal\Api\Enums\V1\TaskQueueType;
use Temporal\Api\Taskqueue\V1\TaskQueue;
use Temporal\Api\Workflowservice\V1\DescribeTaskQueueRequest;

/**
 * The real worker (`bin/temporal-worker.php` under RoadRunner) in the `test` environment: it polls the test task queue
 * of the dev Temporal server of the compose stack and runs the real workflows and activities against `app_test` and the
 * gateway emulator. The compose `temporal-worker` serves another queue (`order-saga`), so the two never meet.
 */
final class TemporalTestWorker
{
    private ?Process $process = null;

    /**
     * @param non-empty-string $temporalAddress
     */
    public function start(string $temporalAddress): void
    {
        $root = dirname(__DIR__, 3);

        // Workflows left Running on the shared queue by other tests or earlier runs would be polled by this worker too.
        TemporalTestQueue::terminateRunning($temporalAddress);

        $this->process = new Process(
            ['rr', 'serve', '-c', $root . '/.rr.yaml', '-o', 'rpc.listen=tcp://127.0.0.1:6002', '-o', 'logs.level=error'],
            $root,
            ['APP_ENV' => 'test', 'APP_DEBUG' => '0', 'TEMPORAL_ADDRESS' => $temporalAddress, 'TEMPORAL_TASK_QUEUE' => TemporalTestQueue::name()],
        );
        $this->process->start();

        $this->waitUntilPolling($temporalAddress, (int) $this->process->getStartTime());
    }

    /**
     * Instead of a fixed sleep: returns when this worker (not a dead one that polled earlier: `lastAccessTime` after
     * the start) polls both the workflow and the activity queue, or fails with the worker's own output.
     *
     * @param non-empty-string $temporalAddress
     */
    private function waitUntilPolling(string $temporalAddress, int $startedAt): void
    {
        $client = (new TemporalClientFactory($temporalAddress))->create()->getServiceClient();
        $polling = [];

        for ($attempt = 0; $attempt < 150; ++$attempt) {
            if ($this->process === null || !$this->process->isRunning()) {
                throw new \RuntimeException('The test worker exited early: ' . $this->output());
            }

            foreach ([TaskQueueType::TASK_QUEUE_TYPE_WORKFLOW, TaskQueueType::TASK_QUEUE_TYPE_ACTIVITY] as $type) {
                try {
                    $response = $client->DescribeTaskQueue(
                        (new DescribeTaskQueueRequest())->setNamespace('default')->setTaskQueue((new TaskQueue())->setName(TemporalTestQueue::name()))->setTaskQueueType($type),
                    );
                } catch (\Throwable) {
                    continue;
                }

                foreach ($response->getPollers() as $poller) {
                    if (($poller->getLastAccessTime()?->getSeconds() ?? 0) >= $startedAt) {
                        $polling[$type] = true;
                    }
                }
            }

            if (count($polling) === 2) {
                return;
            }

            usleep(200_000);
        }

        throw new \RuntimeException('The test worker did not start polling in 30 s. ' . $this->output());
    }

    /** What the worker printed so far (RoadRunner at log level `error` and PHP errors): the first place to look when a run times out. */
    public function output(): string
    {
        return $this->process === null ? '' : trim($this->process->getOutput() . "\n" . $this->process->getErrorOutput());
    }

    public function stop(): void
    {
        $this->process?->stop(5);
        $this->process = null;
    }
}
