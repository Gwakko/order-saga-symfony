<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\CancelOrder;

use App\Ordering\Application\Port\OrderRepository;
use App\Ordering\Application\Port\OrderSagaSignals;
use App\Ordering\Domain\Order;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Exception\ConcurrencyConflict;
use App\Shared\Application\Exception\OrderNotFound;
use App\Shared\Application\Port\TransactionManager;
use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Domain\Identifier\OrderId;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Records the cancellation flag, then signals the workflow. The flag is the source of truth: a lost signal does not
 * undo it, because the workflow re-reads the flag before every step (03_saga.md section 5).
 */
final readonly class CancelOrderHandler implements CommandHandler
{
    /** Total number of tries when the order is modified concurrently (EC-79). */
    public const int MAX_ATTEMPTS = 3;

    public function __construct(
        private OrderRepository $orders,
        private OrderSagaSignals $signals,
        private TransactionManager $transactions,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(CancelOrderCommand $command): CancelOrderOutput
    {
        $orderId = OrderId::fromString($command->orderId);
        $customerId = CustomerId::fromString($command->customerId);

        $order = $this->requestWithRetry($orderId, $customerId);

        try {
            $this->signals->requestCancellation($orderId);
        } catch (\Throwable $e) {
            $this->logger->warning('Cancellation signal was not delivered, the flag in the database remains.', [
                'orderId' => $orderId->value,
                'error' => $e->getMessage(),
            ]);
        }

        return new CancelOrderOutput($order->id->value, $order->status, $order->cancellationRequested);
    }

    private function requestWithRetry(OrderId $orderId, CustomerId $customerId): Order
    {
        $attempt = 1;

        while (true) {
            try {
                return $this->requestOnce($orderId, $customerId);
            } catch (ConcurrencyConflict $e) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw $e;
                }

                ++$attempt;
            }
        }
    }

    private function requestOnce(OrderId $orderId, CustomerId $customerId): Order
    {
        $order = $this->orders->get($orderId);

        // A foreign order looks exactly like a missing one (EC-70).
        if (!$order->customerId->equals($customerId)) {
            throw new OrderNotFound($orderId);
        }

        $updated = $order->requestCancellation(\DateTimeImmutable::createFromInterface($this->clock->now()));

        if ($updated !== $order) {
            $this->transactions->transactional(fn() => $this->orders->save($updated));
        }

        return $updated;
    }
}
