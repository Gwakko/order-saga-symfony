<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\CompleteOrder;

use App\Ordering\Application\Exception\DeliveredKeyNotFound;
use App\Ordering\Application\OrderMutator;
use App\Ordering\Application\Port\DeliveredKeyReader;
use App\Ordering\Domain\Order;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\ProductKeyId;

/**
 * Hands the key over. The code is read only to build the masked form for the event (INV-17).
 */
final readonly class CompleteOrderHandler implements CommandHandler
{
    public function __construct(private OrderMutator $mutator, private DeliveredKeyReader $keys) {}

    public function __invoke(CompleteOrderCommand $command): CompleteOrderOutput
    {
        $keyId = ProductKeyId::fromString($command->keyId);
        $code = $this->keys->codeOf($keyId) ?? throw new DeliveredKeyNotFound($keyId);

        $order = $this->mutator->apply(
            OrderId::fromString($command->orderId),
            static fn(Order $order, \DateTimeImmutable $now): Order => $order->complete($keyId, $code, $now),
        );

        return new CompleteOrderOutput($order->version);
    }
}
