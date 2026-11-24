<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\FailOrder;

use App\Ordering\Application\OrderMutator;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Identifier\OrderId;

/**
 * `Pending` to `Failed` (for example `OutOfStock`). A repeat on an already failed order is a no-op.
 */
final readonly class FailOrderHandler implements CommandHandler
{
    public function __construct(private OrderMutator $mutator) {}

    public function __invoke(FailOrderCommand $command): void
    {
        $this->mutator->apply(
            OrderId::fromString($command->orderId),
            static fn(Order $order, \DateTimeImmutable $now): Order => $order->status === OrderStatus::Failed ? $order : $order->fail($command->reason, $now),
        );
    }
}
