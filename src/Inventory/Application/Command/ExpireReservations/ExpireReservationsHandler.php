<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\ExpireReservations;

use App\Inventory\Application\Port\KeyInventory;
use App\Inventory\Application\Port\ReservationRepository;
use App\Inventory\Domain\KeyReservation;
use App\Inventory\Domain\KeyStatus;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Exception\ConcurrencyConflict;
use App\Shared\Application\Port\TransactionManager;
use Psr\Clock\ClockInterface;

/**
 * Sweeper: `Active` reservations past `expiresAt` become `Expired` and their keys `Available` again.
 * Each reservation has its own transaction. One that lost a race with `ConsumeReservation` or another sweeper
 * (CAS on the status) is skipped, not counted.
 */
final readonly class ExpireReservationsHandler implements CommandHandler
{
    public function __construct(
        private KeyInventory $keys,
        private ReservationRepository $reservations,
        private TransactionManager $transactions,
        private ClockInterface $clock,
    ) {}

    public function __invoke(ExpireReservationsCommand $command): ExpireReservationsOutput
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $expired = 0;

        foreach ($this->reservations->findExpired($now, $command->limit) as $reservation) {
            try {
                $this->transactions->transactional(fn() => $this->expire($reservation, $now));
                ++$expired;
            } catch (ConcurrencyConflict) {
                // The next sweep sees the winner's state.
            }
        }

        return new ExpireReservationsOutput($expired);
    }

    private function expire(KeyReservation $reservation, \DateTimeImmutable $now): void
    {
        $key = $this->keys->find($reservation->keyId);

        if ($key !== null && $key->status === KeyStatus::Reserved) {
            $this->keys->save($key->release());
        }

        $this->reservations->save($reservation->expire($now));
    }
}
