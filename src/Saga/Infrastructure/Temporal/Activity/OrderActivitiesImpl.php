<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Activity;

use App\Ordering\Application\Command\CancelOrderBeforeStart\CancelOrderBeforeStartCommand;
use App\Ordering\Application\Command\CompleteOrder\CompleteOrderCommand;
use App\Ordering\Application\Command\CompleteOrder\CompleteOrderOutput;
use App\Ordering\Application\Command\FailOrder\FailOrderCommand;
use App\Ordering\Application\Command\FinishOrderCompensation\FinishOrderCompensationCommand;
use App\Ordering\Application\Command\FinishOrderRefund\FinishOrderRefundCommand;
use App\Ordering\Application\Command\FinishOrderCompensation\FinishOrderCompensationOutput;
use App\Ordering\Application\Command\MarkOrderKeyReserved\MarkOrderKeyReservedCommand;
use App\Ordering\Application\Command\MarkOrderKeyReserved\MarkOrderKeyReservedOutput;
use App\Ordering\Application\Command\MarkOrderManualReview\MarkOrderManualReviewCommand;
use App\Ordering\Application\Command\MarkOrderPaid\MarkOrderPaidCommand;
use App\Ordering\Application\Command\MarkOrderPaid\MarkOrderPaidOutput;
use App\Ordering\Application\Command\StartOrderCompensation\StartOrderCompensationCommand;
use App\Ordering\Application\Port\OrderRepository;
use App\Shared\Domain\FailureReason;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Infrastructure\Bus\CommandBus;

final readonly class OrderActivitiesImpl implements OrderActivities
{
    public function __construct(
        private CommandBus $commands,
        private OrderRepository $orders,
    ) {}

    public function markKeyReserved(string $orderId, string $reservationId): int
    {
        return $this->commands->execute(new MarkOrderKeyReservedCommand($orderId, $reservationId), MarkOrderKeyReservedOutput::class)->version;
    }

    public function markPaid(string $orderId, string $paymentId): int
    {
        return $this->commands->execute(new MarkOrderPaidCommand($orderId, $paymentId), MarkOrderPaidOutput::class)->version;
    }

    public function complete(string $orderId, string $keyId): int
    {
        return $this->commands->execute(new CompleteOrderCommand($orderId, $keyId), CompleteOrderOutput::class)->version;
    }

    public function fail(string $orderId, string $reason): bool
    {
        $this->commands->dispatch(new FailOrderCommand($orderId, FailureReason::from($reason)));

        return true;
    }

    public function startCompensation(string $orderId, string $reason): bool
    {
        $this->commands->dispatch(new StartOrderCompensationCommand($orderId, FailureReason::from($reason)));

        return true;
    }

    public function finishCompensation(string $orderId): string
    {
        return $this->commands->execute(new FinishOrderCompensationCommand($orderId), FinishOrderCompensationOutput::class)->status->value;
    }

    public function markManualReview(string $orderId, string $reason, string $step): bool
    {
        $this->commands->dispatch(new MarkOrderManualReviewCommand($orderId, FailureReason::from($reason), $step));

        return true;
    }

    /** A plain read of the flag: thin enough to skip a query handler (like the product id read in `reserveKey`). */
    public function isCancellationRequested(string $orderId): bool
    {
        return $this->orders->get(OrderId::fromString($orderId))->cancellationRequested;
    }

    public function cancelBeforeStart(string $orderId): bool
    {
        $this->commands->dispatch(new CancelOrderBeforeStartCommand($orderId));

        return true;
    }

    public function finishRefund(string $orderId): bool
    {
        $this->commands->dispatch(new FinishOrderRefundCommand($orderId));

        return true;
    }
}
