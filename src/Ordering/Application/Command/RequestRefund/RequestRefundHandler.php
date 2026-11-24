<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\RequestRefund;

use App\Ordering\Application\Port\OrderRepository;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Exception\OrderNotFound;
use App\Shared\Application\Port\TransactionManager;
use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\RefundWindow;
use Psr\Clock\ClockInterface;

/**
 * Starts the refund of a completed order. `OrderRefundStarted` goes to the outbox and starts `RefundWorkflow`.
 */
final readonly class RequestRefundHandler implements CommandHandler
{
    public function __construct(
        private OrderRepository $orders,
        private TransactionManager $transactions,
        private ClockInterface $clock,
        private RefundWindow $window = new RefundWindow(),
    ) {}

    public function __invoke(RequestRefundCommand $command): RequestRefundOutput
    {
        $orderId = OrderId::fromString($command->orderId);
        $order = $this->orders->get($orderId);

        if (!$order->customerId->equals(CustomerId::fromString($command->customerId))) {
            throw new OrderNotFound($orderId);
        }

        // A repeat is answered with the current status (idempotent).
        if (in_array($order->status, [OrderStatus::Refunding, OrderStatus::Refunded], true)) {
            return new RequestRefundOutput($order->id->value, $order->status);
        }

        $refunding = $order->startRefund(\DateTimeImmutable::createFromInterface($this->clock->now()), $this->window);
        $this->transactions->transactional(fn() => $this->orders->save($refunding));

        return new RequestRefundOutput($refunding->id->value, $refunding->status);
    }
}
