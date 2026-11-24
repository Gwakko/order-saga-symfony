<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Schedule;

use App\Payment\Application\Command\ReconcilePayments\ReconcilePaymentsCommand;
use App\Payment\Application\Command\ReconcilePayments\ReconcilePaymentsHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ReconcilePaymentsTickHandler
{
    public function __construct(private ReconcilePaymentsHandler $handler, private LoggerInterface $logger) {}

    public function __invoke(ReconcilePaymentsTick $tick): void
    {
        $result = ($this->handler)(new ReconcilePaymentsCommand($tick->olderThanSeconds, $tick->limit));

        if ($result->checked > 0) {
            $this->logger->info('Reconciled payments.', ['checked' => $result->checked, 'succeeded_late' => $result->succeededLate, 'refunded' => $result->refunded]);
        }
    }
}
