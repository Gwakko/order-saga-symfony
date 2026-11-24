<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\RevokeKey;

use App\Inventory\Application\Exception\KeyNotFound;
use App\Inventory\Application\Port\KeyInventory;
use App\Inventory\Application\Port\ReservationRepository;
use App\Inventory\Domain\KeyStatus;
use App\Inventory\Domain\ReservationStatus;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Port\TransactionManager;
use App\Shared\Domain\Identifier\ProductKeyId;
use Psr\Clock\ClockInterface;

/**
 * Simulates the supplier taking a key back (EC-18). The key becomes `Revoked` whatever its status; a reservation
 * is not touched, `ConsumeReservation` notices the revoked key. Revoking twice is a no-op.
 */
final readonly class RevokeKeyHandler implements CommandHandler
{
    public function __construct(
        private KeyInventory $keys,
        private ReservationRepository $reservations,
        private TransactionManager $transactions,
        private ClockInterface $clock,
    ) {}

    public function __invoke(RevokeKeyCommand $command): void
    {
        $keyId = ProductKeyId::fromString($command->keyId);
        $key = $this->keys->find($keyId) ?? throw new KeyNotFound($keyId);

        if ($key->status === KeyStatus::Revoked) {
            return;
        }

        $reservation = $this->reservations->findByKey($keyId);
        $orderId = $reservation !== null && in_array($reservation->status, [ReservationStatus::Active, ReservationStatus::Consumed], true)
            ? $reservation->orderId
            : null;

        $revoked = $key->revoke($orderId, \DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->transactions->transactional(fn() => $this->keys->save($revoked));
    }
}
