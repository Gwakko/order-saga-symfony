<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\FinishOrderCompensation;

use App\Ordering\Application\OrderMutator;
use App\Ordering\Domain\Order;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Identifier\OrderId;

/**
 * Closes the compensation: `Cancelled` when the customer asked for it, `Failed` with the stored reason otherwise.
 */
final readonly class FinishOrderCompensationHandler implements CommandHandler
{
    public function __construct(private OrderMutator $mutator) {}

    public function __invoke(FinishOrderCompensationCommand $command): FinishOrderCompensationOutput
    {
        $order = $this->mutator->apply(
            OrderId::fromString($command->orderId),
            static fn(Order $order, \DateTimeImmutable $now): Order => $order->finishCompensation($now),
        );

        return new FinishOrderCompensationOutput($order->status);
    }
}
