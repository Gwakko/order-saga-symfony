<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Schedule;

use App\Inventory\Application\Command\ExpireReservations\ExpireReservationsCommand;
use App\Inventory\Application\Command\ExpireReservations\ExpireReservationsHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ExpireReservationsTickHandler
{
    public function __construct(private ExpireReservationsHandler $handler, private LoggerInterface $logger) {}

    public function __invoke(ExpireReservationsTick $tick): void
    {
        $result = ($this->handler)(new ExpireReservationsCommand($tick->limit));

        if ($result->expiredCount > 0) {
            $this->logger->info('Expired reservations.', ['count' => $result->expiredCount]);
        }
    }
}
