<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\ReserveKey;

use App\Inventory\Application\Exception\OutOfStock;
use App\Inventory\Application\Port\KeyInventory;
use App\Inventory\Application\Port\ReservationRepository;
use App\Inventory\Domain\KeyReservation;
use App\Inventory\Domain\ReservationStatus;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Port\IdGenerator;
use App\Shared\Application\Port\TransactionManager;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Identifier\ReservationId;
use Psr\Clock\ClockInterface;

/**
 * Holds one key for the order. Idempotent by order id (EC-23, INV-05): a repeat returns the existing hold.
 * Key and reservation change in one transaction (01_domain.md section 4.3).
 */
final readonly class ReserveKeyHandler implements CommandHandler
{
    public function __construct(
        private KeyInventory $keys,
        private ReservationRepository $reservations,
        private TransactionManager $transactions,
        private IdGenerator $ids,
        private ClockInterface $clock,
        private int $ttlSeconds = 900,
    ) {}

    public function __invoke(ReserveKeyCommand $command): ReserveKeyOutput
    {
        $orderId = OrderId::fromString($command->orderId);
        $productId = ProductId::fromString($command->productId);

        $reservation = $this->transactions->transactional(fn(): KeyReservation => $this->reserve($orderId, $productId));

        return new ReserveKeyOutput($reservation->id->value, $reservation->keyId->value, $reservation->expiresAt);
    }

    private function reserve(OrderId $orderId, ProductId $productId): KeyReservation
    {
        $existing = $this->reservations->findByOrder($orderId);

        if ($existing !== null && in_array($existing->status, [ReservationStatus::Active, ReservationStatus::Consumed], true)) {
            return $existing;
        }

        $key = $this->keys->lockAvailable($productId) ?? throw new OutOfStock($productId);
        $reserved = $key->reserve();
        $reservation = KeyReservation::create(
            ReservationId::fromString($this->ids->next()),
            $orderId,
            $key->id,
            $productId,
            \DateTimeImmutable::createFromInterface($this->clock->now()),
            $this->ttlSeconds,
        );

        $this->keys->save($reserved);
        $this->reservations->save($reservation);

        return $reservation;
    }
}
