<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\PlaceOrder;

use App\Ordering\Application\Exception\ProductNotFound;
use App\Ordering\Application\Exception\ProductUnavailable;
use App\Ordering\Application\Port\OrderRepository;
use App\Ordering\Application\Port\ProductCatalog;
use App\Ordering\Domain\Order;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Output\MoneyOutput;
use App\Shared\Application\Port\TransactionManager;
use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Money;
use Psr\Clock\ClockInterface;

/**
 * Creates the order at the catalog price (INV-13). The saga starts later from the `OrderPlaced` outbox event.
 */
final readonly class PlaceOrderHandler implements CommandHandler
{
    public function __construct(
        private OrderRepository $orders,
        private ProductCatalog $catalog,
        private TransactionManager $transactions,
        private ClockInterface $clock,
    ) {}

    public function __invoke(PlaceOrderCommand $command): PlaceOrderOutput
    {
        $productId = ProductId::fromString($command->productId);
        $product = $this->catalog->find($productId) ?? throw new ProductNotFound($productId);

        if (!$product->active) {
            throw new ProductUnavailable($productId);
        }

        $order = Order::place(
            OrderId::fromString($command->orderId),
            CustomerId::fromString($command->customerId),
            $productId,
            $product->price,
            Money::positive($command->expectedAmount, $command->expectedCurrency),
            \DateTimeImmutable::createFromInterface($this->clock->now()),
        );

        $this->transactions->transactional(fn() => $this->orders->save($order));

        return new PlaceOrderOutput($order->id->value, $order->status, MoneyOutput::fromMoney($order->price), $order->createdAt);
    }
}
