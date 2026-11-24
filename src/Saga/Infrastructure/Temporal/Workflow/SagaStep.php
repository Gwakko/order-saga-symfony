<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Workflow;

/** Steps of `OrderFulfillmentWorkflow` and `RefundWorkflow`; the value is shown by the `progress` query and recorded for manual review. */
enum SagaStep: string
{
    case ReserveKey = 'ReserveKey';
    case MarkKeyReserved = 'MarkOrderKeyReserved';
    case ChargePayment = 'ChargePayment';
    case MarkPaid = 'MarkOrderPaid';
    case ConsumeReservation = 'ConsumeReservation';
    case CompleteOrder = 'CompleteOrder';
    case RevokeSoldKey = 'RevokeSoldKey';
    case RefundPayment = 'RefundPayment';
    case FinishOrderRefund = 'FinishOrderRefund';
}
