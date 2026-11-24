<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\CancelOrderBeforeStart;

use App\Ordering\Application\OrderMutator;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Identifier\OrderId;

/**
 * The cancellation flag was set before the saga did anything: `Pending` to `Cancelled`, no compensation needed.
 */
final readonly class CancelOrderBeforeStartHandler implements CommandHandler
{
    public function __construct(private OrderMutator $mutator) {}

    public function __invoke(CancelOrderBeforeStartCommand $command): void
    {
        $this->mutator->apply(
            OrderId::fromString($command->orderId),
            static fn(Order $order, \DateTimeImmutable $now): Order => $order->status === OrderStatus::Cancelled ? $order : $order->cancelBeforeStart($now),
        );
    }
}
