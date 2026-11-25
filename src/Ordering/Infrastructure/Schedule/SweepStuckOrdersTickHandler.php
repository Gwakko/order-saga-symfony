<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Schedule;

use App\Ordering\Application\Command\SweepStuckOrders\SweepStuckOrdersCommand;
use App\Ordering\Application\Command\SweepStuckOrders\SweepStuckOrdersHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SweepStuckOrdersTickHandler
{
    public function __construct(
        private SweepStuckOrdersHandler $handler,
        private LoggerInterface $logger,
        #[Autowire('%app.stuck_orders.older_than_seconds%')]
        private int $olderThanSeconds,
    ) {}

    public function __invoke(SweepStuckOrdersTick $tick): void
    {
        $result = ($this->handler)(new SweepStuckOrdersCommand($this->olderThanSeconds, $tick->limit));

        if ($result->checked > 0) {
            $this->logger->info('Swept stuck orders.', ['checked' => $result->checked, 'interrupted' => $result->interrupted, 'still_running' => $result->stillRunning, 'skipped' => $result->skipped]);
        }
    }
}
