<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Application\Exception\WorkflowStatusUnavailable;
use App\Shared\Domain\Identifier\OrderId;

/**
 * Asks the workflow engine whether the saga of an order is still being driven (M8a sweeper).
 */
interface WorkflowStatusChecker
{
    /**
     * True only while the workflow execution is running. A workflow that never existed, completed, failed, timed out,
     * was cancelled or terminated is not running.
     *
     * @throws WorkflowStatusUnavailable when the engine cannot be asked
     */
    public function isRunning(OrderId $orderId, SagaWorkflowKind $kind): bool;
}
