<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\ListCustomerOrders;

use App\Ordering\Application\Port\OrderReadModel;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Domain\Identifier\OrderId;

final readonly class ListCustomerOrdersHandler implements QueryHandler
{
    public function __construct(private OrderReadModel $orders) {}

    public function __invoke(ListCustomerOrdersQuery $query): ListCustomerOrdersOutput
    {
        $after = $query->cursor === null ? null : OrderCursor::decode($query->cursor);

        // One extra row tells whether another page exists.
        $rows = $this->orders->list(CustomerId::fromString($query->customerId), $query->status, $query->limit + 1, $after);
        $items = array_slice($rows, 0, $query->limit);
        $last = end($items);

        $nextCursor = count($rows) > $query->limit && $last !== false
            ? (new OrderCursor($last->createdAt, OrderId::fromString($last->orderId)))->encode()
            : null;

        return new ListCustomerOrdersOutput($items, $nextCursor);
    }
}
