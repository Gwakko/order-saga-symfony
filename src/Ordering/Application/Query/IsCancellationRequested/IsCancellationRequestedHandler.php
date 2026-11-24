<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\IsCancellationRequested;

use App\Ordering\Application\Port\OrderRepository;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Domain\Identifier\OrderId;

/**
 * Read by the workflow before each step (03_saga.md section 2): the flag in the database is the source of truth.
 */
final readonly class IsCancellationRequestedHandler implements QueryHandler
{
    public function __construct(private OrderRepository $orders) {}

    public function __invoke(IsCancellationRequestedQuery $query): IsCancellationRequestedOutput
    {
        return new IsCancellationRequestedOutput($this->orders->get(OrderId::fromString($query->orderId))->cancellationRequested);
    }
}
