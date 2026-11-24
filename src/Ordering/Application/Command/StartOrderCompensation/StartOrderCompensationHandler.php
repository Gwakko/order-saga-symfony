<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\StartOrderCompensation;

use App\Ordering\Application\OrderMutator;
use App\Ordering\Domain\Order;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Identifier\OrderId;

final readonly class StartOrderCompensationHandler implements CommandHandler
{
    public function __construct(private OrderMutator $mutator) {}

    public function __invoke(StartOrderCompensationCommand $command): void
    {
        $this->mutator->apply(
            OrderId::fromString($command->orderId),
            static fn(Order $order, \DateTimeImmutable $now): Order => $order->startCompensation($command->reason, $now),
        );
    }
}
