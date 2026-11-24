<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\ReleaseReservation;

use App\Inventory\Application\Port\KeyInventory;
use App\Inventory\Application\Port\ReservationRepository;
use App\Inventory\Domain\KeyStatus;
use App\Inventory\Domain\ReservationStatus;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Port\TransactionManager;
use App\Shared\Domain\Identifier\OrderId;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Compensation: `Active` reservation to `Released`, key back to `Available`. A sold key is never put back on sale
 * (EC-22): the call answers `released = false` and logs a warning.
 */
final readonly class ReleaseReservationHandler implements CommandHandler
{
    public function __construct(
        private KeyInventory $keys,
        private ReservationRepository $reservations,
        private TransactionManager $transactions,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(ReleaseReservationCommand $command): ReleaseReservationOutput
    {
        $orderId = OrderId::fromString($command->orderId);
        $reservation = $this->reservations->findByOrder($orderId);

        if ($reservation === null) {
            return new ReleaseReservationOutput(true);
        }

        if ($reservation->status === ReservationStatus::Consumed) {
            $this->logger->warning('Reservation was already consumed, the key stays sold.', ['orderId' => $orderId->value]);

            return new ReleaseReservationOutput(false);
        }

        if ($reservation->status !== ReservationStatus::Active) {
            return new ReleaseReservationOutput(true);
        }

        $released = $reservation->release(\DateTimeImmutable::createFromInterface($this->clock->now()));
        $key = $this->keys->find($reservation->keyId);

        $this->transactions->transactional(function () use ($released, $key): void {
            // A key the supplier revoked meanwhile stays revoked.
            if ($key !== null && $key->status === KeyStatus::Reserved) {
                $this->keys->save($key->release());
            }

            $this->reservations->save($released);
        });

        return new ReleaseReservationOutput(true);
    }
}
