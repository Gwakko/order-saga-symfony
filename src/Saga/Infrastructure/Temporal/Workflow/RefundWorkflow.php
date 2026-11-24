<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Workflow;

use Temporal\Workflow\QueryMethod;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

/** Refund of a delivered order (03_saga.md section 1, EC-59): revoke the key, return the money, finish the order. */
#[WorkflowInterface]
interface RefundWorkflow
{
    public const string TYPE = 'OrderRefund';

    /**
     * @return \Generator<int, mixed, mixed, string> the final `OrderStatus` value (Refunded or ManualReview)
     */
    #[WorkflowMethod(name: self::TYPE)]
    public function refund(string $orderId): \Generator;

    /** The step the refund is at now; for debugging only. */
    #[QueryMethod(name: 'progress')]
    public function progress(): string;
}
