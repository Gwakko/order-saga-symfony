<?php

declare(strict_types=1);

namespace App\Ordering\Application;

use App\Ordering\Application\Port\OrderRepository;
use App\Ordering\Domain\Order;
use App\Shared\Application\Port\TransactionManager;
use App\Shared\Domain\Identifier\OrderId;
use Psr\Clock\ClockInterface;

/**
 * The shape shared by the saga's order commands: load, apply one transition, save when it changed anything.
 * An idempotent repeat returns the same instance and writes nothing (no event, no version bump).
 */
final readonly class OrderMutator
{
    public function __construct(
        private OrderRepository $orders,
        private TransactionManager $transactions,
        private ClockInterface $clock,
    ) {}

    /**
     * @param \Closure(Order, \DateTimeImmutable): Order $transition
     */
    public function apply(OrderId $orderId, \Closure $transition): Order
    {
        $order = $this->orders->get($orderId);
        $updated = $transition($order, \DateTimeImmutable::createFromInterface($this->clock->now()));

        if ($updated !== $order) {
            $this->transactions->transactional(fn() => $this->orders->save($updated));
        }

        return $updated;
    }
}
