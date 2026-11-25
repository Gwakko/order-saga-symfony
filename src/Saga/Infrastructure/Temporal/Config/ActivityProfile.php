<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Config;

/**
 * One row of the timeout table (03_saga.md section 3). Activities that share a row share the profile: all the small
 * order transitions (`MarkOrder*`, `CompleteOrder`, ...) are `OrderStep`.
 */
enum ActivityProfile: string
{
    case ReserveKey = 'reserve_key';
    case OrderStep = 'order_step';
    case ChargePayment = 'charge_payment';
    /** `InspectGatewayCharge`, served by the Go worker on its own task queue (docs/spec/09_go_worker.md section 4.7). */
    case InspectGatewayCharge = 'inspect_gateway_charge';
    /** The repeated `ChargePayment` that confirms a charge the gateway already holds (same idempotency key). */
    case ChargeRecovery = 'charge_recovery';
    case ConsumeReservation = 'consume_reservation';
    case RevokeSoldKey = 'revoke_sold_key';
    case RefundPayment = 'refund_payment';
    case ReleaseReservation = 'release_reservation';
}
