<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\ConsumeReservation;

use App\Inventory\Application\Exception\KeyIsRevoked;
use App\Inventory\Application\Exception\KeyNotFound;
use App\Inventory\Application\Exception\ReservationNotFound;
use App\Inventory\Application\Port\KeyInventory;
use App\Inventory\Application\Port\ReservationRepository;
use App\Inventory\Domain\KeyStatus;
use App\Inventory\Domain\ReservationStatus;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Port\TransactionManager;
use App\Shared\Domain\Identifier\OrderId;
use Psr\Clock\ClockInterface;

/**
 * The point of no return: reservation `Consumed`, key `Sold`. Raises `ReservationHasExpired` at or after the expiry
 * (INV-14, EC-16) and `KeyIsRevoked` when the supplier took the key back meanwhile (EC-18). Idempotent by order id.
 */
final readonly class ConsumeReservationHandler implements CommandHandler
{
    public function __construct(
        private KeyInventory $keys,
        private ReservationRepository $reservations,
        private TransactionManager $transactions,
        private ClockInterface $clock,
    ) {}

    public function __invoke(ConsumeReservationCommand $command): ConsumeReservationOutput
    {
        $orderId = OrderId::fromString($command->orderId);
        $reservation = $this->reservations->findByOrder($orderId) ?? throw new ReservationNotFound($orderId);

        if ($reservation->status === ReservationStatus::Consumed) {
            return new ConsumeReservationOutput($reservation->keyId->value);
        }

        $key = $this->keys->find($reservation->keyId) ?? throw new KeyNotFound($reservation->keyId);

        if ($key->status === KeyStatus::Revoked) {
            throw new KeyIsRevoked($key->id);
        }

        $consumed = $reservation->consume($orderId, \DateTimeImmutable::createFromInterface($this->clock->now()));
        $sold = $key->sell();

        $this->transactions->transactional(function () use ($consumed, $sold): void {
            $this->keys->save($sold);
            $this->reservations->save($consumed);
        });

        return new ConsumeReservationOutput($consumed->keyId->value);
    }
}
