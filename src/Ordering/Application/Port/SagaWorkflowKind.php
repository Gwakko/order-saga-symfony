<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

/**
 * Which Temporal workflow is expected to be driving an order that is in an intermediate status.
 */
enum SagaWorkflowKind
{
    /** `order-{orderId}`: Pending, KeyReserved, Paid, Compensating. */
    case Fulfillment;

    /** `refund-{orderId}`: Refunding. */
    case Refund;
}
