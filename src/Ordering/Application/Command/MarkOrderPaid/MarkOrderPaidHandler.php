<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\MarkOrderPaid;

use App\Ordering\Application\OrderMutator;
use App\Ordering\Domain\Order;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\PaymentId;

final readonly class MarkOrderPaidHandler implements CommandHandler
{
    public function __construct(private OrderMutator $mutator) {}

    public function __invoke(MarkOrderPaidCommand $command): MarkOrderPaidOutput
    {
        $paymentId = PaymentId::fromString($command->paymentId);
        $order = $this->mutator->apply(
            OrderId::fromString($command->orderId),
            static fn(Order $order, \DateTimeImmutable $now): Order => $order->markPaid($paymentId, $now),
        );

        return new MarkOrderPaidOutput($order->version);
    }
}
