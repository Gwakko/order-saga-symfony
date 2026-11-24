<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Application\Query\GetOrder\OrderDetailsOutput;
use App\Ordering\Application\Query\ListCustomerOrders\OrderCursor;
use App\Ordering\Application\Query\ListCustomerOrders\OrderSummaryOutput;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Domain\Identifier\OrderId;

/**
 * Read side over `order_view` (eventually consistent, EC-54): plain outputs, no domain objects.
 */
interface OrderReadModel
{
    /**
     * Null for a missing order and for an order of another customer alike (EC-70).
     * `keyCode` is filled only for a `Completed` order (INV-17).
     */
    public function find(OrderId $orderId, CustomerId $customerId): ?OrderDetailsOutput;

    /**
     * The customer's orders, newest first (`created_at DESC, order_id DESC`), strictly after the cursor.
     * Returns at most `$limit` items; the handler asks for one more than it shows to detect a next page.
     *
     * @return list<OrderSummaryOutput>
     */
    public function list(CustomerId $customerId, ?OrderStatus $status, int $limit, ?OrderCursor $after): array;
}
