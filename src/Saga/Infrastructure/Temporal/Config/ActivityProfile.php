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
    case ConsumeReservation = 'consume_reservation';
    case RefundPayment = 'refund_payment';
    case ReleaseReservation = 'release_reservation';
}
