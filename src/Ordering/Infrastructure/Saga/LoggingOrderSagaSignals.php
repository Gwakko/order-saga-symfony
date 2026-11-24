<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Saga;

use App\Ordering\Application\Port\OrderSagaSignals;
use App\Shared\Domain\Identifier\OrderId;
use Psr\Log\LoggerInterface;

/**
 * Stand-in until the Temporal adapter exists (M6.2): the cancellation flag is already in the database, and the workflow
 * reads it before every step, so a signal that is only logged loses nothing (03_saga.md section 5).
 */
final readonly class LoggingOrderSagaSignals implements OrderSagaSignals
{
    public function __construct(private LoggerInterface $logger) {}

    public function requestCancellation(OrderId $orderId): void
    {
        $this->logger->info('Cancellation signal not sent: Temporal is not wired yet, the flag in the database is authoritative.', ['orderId' => $orderId->value]);
    }
}
