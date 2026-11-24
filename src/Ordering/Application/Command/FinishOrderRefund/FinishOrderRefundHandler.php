<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\FinishOrderRefund;

use App\Ordering\Application\OrderMutator;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Identifier\OrderId;

final readonly class FinishOrderRefundHandler implements CommandHandler
{
    public function __construct(private OrderMutator $mutator) {}

    public function __invoke(FinishOrderRefundCommand $command): void
    {
        $this->mutator->apply(
            OrderId::fromString($command->orderId),
            static fn(Order $order, \DateTimeImmutable $now): Order => $order->status === OrderStatus::Refunded ? $order : $order->finishRefund($now),
        );
    }
}
