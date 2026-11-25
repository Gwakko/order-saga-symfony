<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use App\Saga\Infrastructure\Temporal\TemporalClientFactory;
use Temporal\Api\Workflowservice\V1\ListWorkflowExecutionsRequest;

/**
 * The dev Temporal server keeps its workflows between runs, and every workflow on the test task queue is picked up by
 * the next real worker (TemporalTestWorker). A workflow that a test (or a crashed run) left Running keeps retrying its
 * activities against a database that no longer has its order, and thousands of them leave the two activity workers of
 * RoadRunner no time for the workflow of the test that is running now ("no free workers in the pool").
 */
final class TemporalTestQueue
{
    /** Fallback of `temporal.task_queue` of the `test` environment (config/packages/temporal.yaml). */
    public const string DEFAULT_NAME = 'order-saga-test';

    /**
     * Gives this PHPUnit process a task queue of its own (once): the dev server of the compose stack is shared and keeps
     * workflows and their tasks between runs, so a fixed queue serves the leftovers of earlier runs to this run's worker.
     * The worker and the consumers started by the tests inherit `TEMPORAL_TASK_QUEUE`. Not called by the `workflow`
     * suite: its replay fixture records the queue name of the activities.
     */
    public static function isolate(): void
    {
        if (($_SERVER['TEMPORAL_TASK_QUEUE'] ?? '') !== '') {
            return;
        }

        $queue = self::DEFAULT_NAME . '-' . bin2hex(random_bytes(6));
        putenv('TEMPORAL_TASK_QUEUE=' . $queue);
        $_ENV['TEMPORAL_TASK_QUEUE'] = $_SERVER['TEMPORAL_TASK_QUEUE'] = $queue;
    }

    /** The queue of this PHPUnit run: `TEMPORAL_TASK_QUEUE` (see `isolate()`), else the default of the test environment. */
    public static function name(): string
    {
        $name = $_SERVER['TEMPORAL_TASK_QUEUE'] ?? $_ENV['TEMPORAL_TASK_QUEUE'] ?? self::DEFAULT_NAME;

        return is_string($name) && $name !== '' ? $name : self::DEFAULT_NAME;
    }

    /** The same, for the server of the compose stack (or `TEMPORAL_ADDRESS`). */
    public static function terminateRunningOnConfiguredServer(): int
    {
        $address = $_SERVER['TEMPORAL_ADDRESS'] ?? $_ENV['TEMPORAL_ADDRESS'] ?? 'temporal:7233';

        return self::terminateRunning(is_string($address) && $address !== '' ? $address : 'temporal:7233');
    }

    /**
     * Terminates every Running workflow of the test task queue and returns how many it terminated.
     *
     * @param non-empty-string $temporalAddress
     */
    public static function terminateRunning(string $temporalAddress): int
    {
        $client = (new TemporalClientFactory($temporalAddress))->create();
        $terminated = 0;

        // Visibility lags a little behind the history, so look again until a page comes back empty.
        for ($pass = 0; $pass < 50; ++$pass) {
            $response = $client->getServiceClient()->ListWorkflowExecutions(
                (new ListWorkflowExecutionsRequest())
                    ->setNamespace('default')
                    ->setQuery(sprintf("ExecutionStatus = 'Running' AND TaskQueue = '%s'", self::name()))
                    ->setPageSize(1000),
            );
            $found = 0;

            foreach ($response->getExecutions() as $execution) {
                ++$found;

                $workflowId = $execution->getExecution()?->getWorkflowId() ?? '';

                if ($workflowId === '') {
                    continue;
                }

                try {
                    $client->newUntypedRunningWorkflowStub($workflowId)->terminate('integration test cleanup');
                    ++$terminated;
                } catch (\Throwable) {
                    // Finished in the meantime.
                }
            }

            if ($found === 0) {
                break;
            }
        }

        return $terminated;
    }
}
