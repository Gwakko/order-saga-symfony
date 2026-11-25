<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Ordering\Application\Port\OrderRepository;
use App\Ordering\Domain\Event\OrderEvent;
use App\Ordering\Domain\Order;
use App\Shared\Application\Exception\ConcurrencyConflict;
use App\Shared\Application\Exception\OrderNotFound;
use App\Shared\Domain\Identifier\OrderId;

/**
 * Keeps the last saved state without pending events (as a real `get` returns it), checks the version like the DBAL
 * repository will (CAS) and collects the events that would go to the outbox.
 */
final class InMemoryOrderRepository implements OrderRepository
{
    /** @var array<string, Order> */
    private array $orders = [];

    /** @var list<OrderEvent> */
    public array $events = [];

    public int $saves = 0;

    private int $failures = 0;

    /**
     * The next `$times` saves fail with ConcurrencyConflict before touching the state.
     */
    public function failNextSaves(int $times): void
    {
        $this->failures = $times;
    }

    public function get(OrderId $id): Order
    {
        return $this->orders[$id->value] ?? throw new OrderNotFound($id);
    }

    /**
     * @return list<Order>
     */
    public function all(): array
    {
        return array_values($this->orders);
    }

    public function find(OrderId $id): ?Order
    {
        return $this->orders[$id->value] ?? null;
    }

    public function save(Order $order): void
    {
        if ($this->failures > 0) {
            --$this->failures;

            throw new ConcurrencyConflict('Order', $order->id->value);
        }

        $expected = $order->version - count($order->pendingEvents);
        $stored = $this->orders[$order->id->value] ?? null;

        if ($stored === null ? $expected !== 0 : $stored->version !== $expected) {
            throw new ConcurrencyConflict('Order', $order->id->value);
        }

        ++$this->saves;
        array_push($this->events, ...$order->pendingEvents);
        $this->orders[$order->id->value] = self::detach($order);
    }

    /**
     * Test setup: stores an order as if it had been saved earlier (events are not collected).
     */
    public function seed(Order $order): void
    {
        $this->orders[$order->id->value] = self::detach($order);
    }

    private static function detach(Order $order): Order
    {
        return Order::reconstitute(
            $order->id,
            $order->customerId,
            $order->productId,
            $order->price,
            $order->status,
            $order->reservationId,
            $order->paymentId,
            $order->deliveredKeyId,
            $order->failureReason,
            $order->compensationReason,
            $order->cancellationRequested,
            $order->createdAt,
            $order->updatedAt,
            $order->completedAt,
            $order->version,
        );
    }
}
