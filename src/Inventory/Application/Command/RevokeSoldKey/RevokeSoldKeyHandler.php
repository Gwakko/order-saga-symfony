<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\RevokeSoldKey;

use App\Inventory\Application\Exception\KeyNotFound;
use App\Inventory\Application\Exception\ReservationNotFound;
use App\Inventory\Application\Port\KeyInventory;
use App\Inventory\Application\Port\ReservationRepository;
use App\Inventory\Domain\KeyStatus;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Port\TransactionManager;
use App\Shared\Domain\Identifier\OrderId;
use Psr\Clock\ClockInterface;

/**
 * Refund path: takes the delivered key out of circulation. A key that is already revoked counts as done (EC-63).
 */
final readonly class RevokeSoldKeyHandler implements CommandHandler
{
    public function __construct(
        private KeyInventory $keys,
        private ReservationRepository $reservations,
        private TransactionManager $transactions,
        private ClockInterface $clock,
    ) {}

    public function __invoke(RevokeSoldKeyCommand $command): RevokeSoldKeyOutput
    {
        $orderId = OrderId::fromString($command->orderId);
        $reservation = $this->reservations->findByOrder($orderId) ?? throw new ReservationNotFound($orderId);
        $key = $this->keys->find($reservation->keyId) ?? throw new KeyNotFound($reservation->keyId);

        if ($key->status !== KeyStatus::Revoked) {
            $revoked = $key->revoke($orderId, \DateTimeImmutable::createFromInterface($this->clock->now()));
            $this->transactions->transactional(fn() => $this->keys->save($revoked));
        }

        return new RevokeSoldKeyOutput($key->id->value);
    }
}
