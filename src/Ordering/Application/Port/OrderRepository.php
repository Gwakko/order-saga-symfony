<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Domain\Order;
use App\Shared\Application\Exception\ConcurrencyConflict;
use App\Shared\Application\Exception\OrderNotFound;
use App\Shared\Domain\Identifier\OrderId;

interface OrderRepository
{
    /**
     * Loads the order with an empty `pendingEvents` list.
     *
     * @throws OrderNotFound
     */
    public function get(OrderId $id): Order;

    /**
     * Writes the new state and the order's pending events (outbox) atomically; expects the stored version to be
     * `$order->version - count($order->pendingEvents)` (INV-10, INV-15).
     *
     * @throws ConcurrencyConflict
     */
    public function save(Order $order): void;
}
