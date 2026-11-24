<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\ListCustomerOrders;

final readonly class ListCustomerOrdersOutput
{
    /**
     * @param list<OrderSummaryOutput> $items
     */
    public function __construct(public array $items, public ?string $nextCursor) {}
}
