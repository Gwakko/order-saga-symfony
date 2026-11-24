<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use App\Ordering\Application\Port\OrderRepository;
use App\Ordering\Domain\Order;
use App\Shared\Domain\Identifier\OrderId;

/**
 * Lets a rival write happen between the first `get` and the following `save` of a use case: a deterministic race.
 */
final class RaceOnceOrderRepository implements OrderRepository
{
    private bool $raced = false;

    /**
     * @param \Closure(): mixed $rival runs once, right after the first read
     */
    public function __construct(private readonly OrderRepository $inner, private readonly \Closure $rival) {}

    public function get(OrderId $id): Order
    {
        $order = $this->inner->get($id);

        if (!$this->raced) {
            $this->raced = true;
            ($this->rival)();
        }

        return $order;
    }

    public function save(Order $order): void
    {
        $this->inner->save($order);
    }
}
