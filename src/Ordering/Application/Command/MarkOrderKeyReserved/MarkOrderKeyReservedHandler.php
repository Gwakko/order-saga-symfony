<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\MarkOrderKeyReserved;

use App\Ordering\Application\OrderMutator;
use App\Ordering\Domain\Order;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\ReservationId;

final readonly class MarkOrderKeyReservedHandler implements CommandHandler
{
    public function __construct(private OrderMutator $mutator) {}

    public function __invoke(MarkOrderKeyReservedCommand $command): MarkOrderKeyReservedOutput
    {
        $reservationId = ReservationId::fromString($command->reservationId);
        $order = $this->mutator->apply(
            OrderId::fromString($command->orderId),
            static fn(Order $order, \DateTimeImmutable $now): Order => $order->markKeyReserved($reservationId, $now),
        );

        return new MarkOrderKeyReservedOutput($order->version);
    }
}
