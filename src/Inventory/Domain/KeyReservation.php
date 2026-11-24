<?php

declare(strict_types=1);

namespace App\Inventory\Domain;

use App\Inventory\Domain\Event\KeyReleased;
use App\Inventory\Domain\Event\KeyReserved;
use App\Inventory\Domain\Event\KeySold;
use App\Inventory\Domain\Event\ReservationEvent;
use App\Inventory\Domain\Event\ReservationExpired;
use App\Inventory\Domain\Exception\IllegalReservationTransition;
use App\Inventory\Domain\Exception\InventoryInvariantViolated;
use App\Inventory\Domain\Exception\ReservationAlreadyConsumed;
use App\Inventory\Domain\Exception\ReservationHasExpired;
use App\Shared\Domain\Event\EventId;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Identifier\ProductKeyId;
use App\Shared\Domain\Identifier\ReservationId;

/**
 * Time-limited hold of one key for one order (01_domain.md section 4.3). Immutable.
 * The expiry boundary is inclusive: at `now == expiresAt` the reservation is already expired (INV-14).
 */
final readonly class KeyReservation
{
    /**
     * @param list<ReservationEvent> $pendingEvents
     */
    private function __construct(
        public ReservationId $id,
        public OrderId $orderId,
        public ProductKeyId $keyId,
        public ProductId $productId,
        public ReservationStatus $status,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $expiresAt,
        public int $version,
        public array $pendingEvents,
    ) {
        if ($version < 1) {
            throw new InventoryInvariantViolated('reservation version must be >= 1');
        }

        if ($expiresAt <= $createdAt) {
            throw new InventoryInvariantViolated('reservation must expire after it was created');
        }
    }

    public static function create(
        ReservationId $id,
        OrderId $orderId,
        ProductKeyId $keyId,
        ProductId $productId,
        \DateTimeImmutable $now,
        int $ttlSeconds,
    ): self {
        if ($ttlSeconds <= 0) {
            throw new InventoryInvariantViolated(sprintf('reservation ttl must be positive, got %d', $ttlSeconds));
        }

        return new self(
            $id,
            $orderId,
            $keyId,
            $productId,
            ReservationStatus::Active,
            $now,
            $now->modify(sprintf('+%d seconds', $ttlSeconds)),
            1,
            [new KeyReserved(EventId::derive($id->value, 1, 'KeyReserved'), $id, $keyId, $productId, $orderId, 1, $now)],
        );
    }

    public static function reconstitute(
        ReservationId $id,
        OrderId $orderId,
        ProductKeyId $keyId,
        ProductId $productId,
        ReservationStatus $status,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $expiresAt,
        int $version,
    ): self {
        return new self($id, $orderId, $keyId, $productId, $status, $createdAt, $expiresAt, $version, []);
    }

    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    /**
     * Turns the hold into a sale. Fails with ReservationHasExpired at or after `expiresAt`, also when the sweeper
     * has already marked it `Expired`. Repeating it for the same order is a no-op.
     */
    public function consume(OrderId $orderId, \DateTimeImmutable $now): self
    {
        return match ($this->status) {
            ReservationStatus::Consumed => $this->orderId->equals($orderId)
                ? $this
                : throw new IllegalReservationTransition($this->status, ReservationStatus::Consumed),
            ReservationStatus::Expired => throw new ReservationHasExpired($this->id),
            ReservationStatus::Released => throw new IllegalReservationTransition($this->status, ReservationStatus::Consumed),
            ReservationStatus::Active => match (true) {
                !$this->orderId->equals($orderId) => throw new IllegalReservationTransition($this->status, ReservationStatus::Consumed),
                $this->isExpiredAt($now) => throw new ReservationHasExpired($this->id),
                default => $this->next(ReservationStatus::Consumed, 'KeySold', $now),
            },
        };
    }

    /**
     * Compensation: gives the key back. Released/Expired: no-op. Consumed: a sold key is refunded, not released.
     */
    public function release(\DateTimeImmutable $now): self
    {
        return match ($this->status) {
            ReservationStatus::Active => $this->next(ReservationStatus::Released, 'KeyReleased', $now),
            ReservationStatus::Released, ReservationStatus::Expired => $this,
            ReservationStatus::Consumed => throw new ReservationAlreadyConsumed($this->id),
        };
    }

    /**
     * Sweeper: only allowed once `now >= expiresAt`.
     */
    public function expire(\DateTimeImmutable $now): self
    {
        if ($this->status !== ReservationStatus::Active || !$this->isExpiredAt($now)) {
            throw new IllegalReservationTransition($this->status, ReservationStatus::Expired);
        }

        return $this->next(ReservationStatus::Expired, 'ReservationExpired', $now);
    }

    private function next(ReservationStatus $status, string $eventType, \DateTimeImmutable $now): self
    {
        $version = $this->version + 1;
        $eventId = EventId::derive($this->id->value, $version, $eventType);

        $event = match ($status) {
            ReservationStatus::Consumed => new KeySold($eventId, $this->id, $this->keyId, $this->productId, $this->orderId, $version, $now),
            ReservationStatus::Released => new KeyReleased($eventId, $this->id, $this->keyId, $this->productId, $this->orderId, $version, $now),
            ReservationStatus::Expired => new ReservationExpired($eventId, $this->id, $this->keyId, $this->productId, $this->orderId, $version, $now),
            ReservationStatus::Active => throw new IllegalReservationTransition($this->status, $status),
        };

        return new self(
            $this->id,
            $this->orderId,
            $this->keyId,
            $this->productId,
            $status,
            $this->createdAt,
            $this->expiresAt,
            $version,
            [...$this->pendingEvents, $event],
        );
    }
}
