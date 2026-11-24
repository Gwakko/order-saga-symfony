<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\MarkOrderManualReview;

use App\Ordering\Application\OrderMutator;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Identifier\OrderId;
use Psr\Log\LoggerInterface;

/**
 * Parks the order for an operator. The domain refuses a second `requireManualReview`, but this activity may run
 * twice, so an order already in manual review is left alone.
 */
final readonly class MarkOrderManualReviewHandler implements CommandHandler
{
    public function __construct(private OrderMutator $mutator, private LoggerInterface $logger) {}

    public function __invoke(MarkOrderManualReviewCommand $command): void
    {
        $order = $this->mutator->apply(
            OrderId::fromString($command->orderId),
            static fn(Order $order, \DateTimeImmutable $now): Order => $order->status === OrderStatus::ManualReview
                ? $order
                : $order->requireManualReview($command->reason, $command->step, $now),
        );

        $this->logger->critical('Order needs manual review.', [
            'orderId' => $order->id->value,
            'reason' => $command->reason->value,
            'step' => $command->step,
        ]);
    }
}
