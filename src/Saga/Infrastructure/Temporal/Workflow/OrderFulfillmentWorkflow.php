<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Workflow;

use Temporal\Workflow\QueryMethod;
use Temporal\Workflow\SignalMethod;
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

    /**
     * The customer asked to cancel. Only sets a flag; the saga reads it (and the flag in the database) before each
     * step, so a step that is running finishes first (03_saga.md section 5). Repeated signals are harmless (EC-43).
     */
    #[SignalMethod(name: 'requestCancellation')]
    public function requestCancellation(): void;

    /** The step the saga is at now; for debugging only. */
    #[QueryMethod(name: 'progress')]
    public function progress(): string;
}
