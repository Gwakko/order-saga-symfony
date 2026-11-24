<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\PhpStan\WorkflowDeterminism;

/** Not a workflow: the rule must stay silent. */
final class OutsideWorkflow
{
    public function run(): int
    {
        random_int(1, 2);
        \Ramsey\Uuid\Uuid::uuid4();

        return time();
    }
}
