<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Workflow;

use Temporal\Workflow\QueryMethod;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

/** Order fulfilment saga (03_saga.md sections 1 and 2): reserve a key, charge, deliver; compensate on failure. */
#[WorkflowInterface]
interface OrderFulfillmentWorkflow
{
    public const string TYPE = 'OrderFulfillment';

    /**
     * @return \Generator<int, mixed, mixed, string> the final `OrderStatus` value
     */
    #[WorkflowMethod(name: self::TYPE)]
    public function fulfil(string $orderId): \Generator;

    /** The step the saga is at now; for debugging only. */
    #[QueryMethod(name: 'progress')]
    public function progress(): string;
}
