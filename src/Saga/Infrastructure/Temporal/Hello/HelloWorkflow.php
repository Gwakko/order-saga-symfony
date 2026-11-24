<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Hello;

use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface HelloWorkflow
{
    public const string TASK_QUEUE = 'order-saga';

    #[WorkflowMethod(name: 'HelloWorkflow')]
    public function run(string $name): \Generator;
}
