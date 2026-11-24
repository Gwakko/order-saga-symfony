<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\PhpStan\WorkflowDeterminism;

final class ViolationViaInterface implements MarkedWorkflow
{
    public function run(): void
    {
        time();
    }
}
