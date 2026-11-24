<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Hello;

use Carbon\CarbonInterval;
use Temporal\Activity\ActivityOptions;
use Temporal\Common\RetryOptions;
use Temporal\Workflow;

final class HelloWorkflowImpl implements HelloWorkflow
{
    public function run(string $name): \Generator
    {
        $activity = Workflow::newActivityStub(
            HelloActivity::class,
            ActivityOptions::new()
                ->withStartToCloseTimeout(CarbonInterval::seconds(10))
                ->withRetryOptions(RetryOptions::new()->withMaximumAttempts(3)),
        );

        return yield $activity->greet($name);
    }
}
