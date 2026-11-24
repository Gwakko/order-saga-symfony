<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Activity;

use App\Inventory\Application\Command\ConsumeReservation\ConsumeReservationCommand;
use App\Inventory\Application\Command\ConsumeReservation\ConsumeReservationOutput;
use App\Inventory\Application\Command\ReleaseReservation\ReleaseReservationCommand;
use App\Inventory\Application\Command\ReleaseReservation\ReleaseReservationOutput;
use App\Inventory\Application\Command\ReserveKey\ReserveKeyCommand;
use App\Inventory\Application\Command\ReserveKey\ReserveKeyOutput;
use App\Ordering\Application\Port\OrderRepository;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Infrastructure\Bus\CommandBus;

/** Thin: the order's product id is read here only because the workflow passes the order id alone (INV-18). */
final readonly class InventoryActivitiesImpl implements InventoryActivities
{
    public function __construct(
        private CommandBus $commands,
        private OrderRepository $orders,
    ) {}

    public function reserveKey(string $orderId): string
    {
        $productId = $this->orders->get(OrderId::fromString($orderId))->productId->value;

        return $this->commands->execute(new ReserveKeyCommand($orderId, $productId), ReserveKeyOutput::class)->reservationId;
    }

    public function consumeReservation(string $orderId): string
    {
        return $this->commands->execute(new ConsumeReservationCommand($orderId), ConsumeReservationOutput::class)->keyId;
    }

    public function releaseReservation(string $orderId): bool
    {
        return $this->commands->execute(new ReleaseReservationCommand($orderId), ReleaseReservationOutput::class)->released;
    }
}
