<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\GetOrder;

use App\Ordering\Application\Port\OrderReadModel;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Application\Exception\OrderNotFound;
use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Domain\Identifier\OrderId;

final readonly class GetOrderHandler implements QueryHandler
{
    public function __construct(private OrderReadModel $orders) {}

    public function __invoke(GetOrderQuery $query): OrderDetailsOutput
    {
        $orderId = OrderId::fromString($query->orderId);

        // Foreign and missing orders look the same: 404, never 403 (EC-70). Right after PlaceOrder the view may lag (EC-54).
        return $this->orders->find($orderId, CustomerId::fromString($query->customerId)) ?? throw new OrderNotFound($orderId);
    }
}
