<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Activity;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

/**
 * Inventory steps of the saga. Arguments and results are scalars: the workflow history stays small and readable, and
 * the data itself lives in the database (INV-18).
 */
#[ActivityInterface]
interface InventoryActivities
{
    /** @return string the reservation id */
    #[ActivityMethod(name: 'ReserveKey')]
    public function reserveKey(string $orderId): string;

    /** @return string the id of the sold key */
    #[ActivityMethod(name: 'ConsumeReservation')]
    public function consumeReservation(string $orderId): string;

    /** @return bool whether a reservation was released */
    #[ActivityMethod(name: 'ReleaseReservation')]
    public function releaseReservation(string $orderId): bool;

    /** Refund path: the delivered key leaves circulation; an already revoked key counts as done (EC-63). @return string the key id */
    #[ActivityMethod(name: 'RevokeSoldKey')]
    public function revokeSoldKey(string $orderId): string;
}
