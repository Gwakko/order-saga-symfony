<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\PhpStan\WorkflowDeterminism;

use Temporal\Workflow\WorkflowInterface;

#[WorkflowInterface]
interface MarkedWorkflow
{
    public function run(): void;
}
