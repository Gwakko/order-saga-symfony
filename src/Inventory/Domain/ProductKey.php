<?php

declare(strict_types=1);

namespace App\Inventory\Domain;

use App\Inventory\Domain\Event\KeyRevoked;
use App\Inventory\Domain\Exception\IllegalKeyTransition;
use App\Inventory\Domain\Exception\InventoryInvariantViolated;
use App\Shared\Domain\Event\EventId;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Identifier\ProductKeyId;
use App\Shared\Domain\KeyCode;

/**
 * A sellable key. Immutable: a transition returns a new instance with version + 1.
 * Only `revoke` raises an event; reservation-level events come from KeyReservation (it knows the order).
 */
final readonly class ProductKey
{
    /**
     * @param list<KeyRevoked> $pendingEvents
     */
    private function __construct(
        public ProductKeyId $id,
        public ProductId $productId,
        public KeyCode $code,
        public KeyStatus $status,
        public int $version,
        public array $pendingEvents,
    ) {
        if ($version < 1) {
            throw new InventoryInvariantViolated('key version must be >= 1');
        }
    }

    public static function add(ProductKeyId $id, ProductId $productId, KeyCode $code): self
    {
        return new self($id, $productId, $code, KeyStatus::Available, 1, []);
    }

    public static function reconstitute(ProductKeyId $id, ProductId $productId, KeyCode $code, KeyStatus $status, int $version): self
    {
        return new self($id, $productId, $code, $status, $version, []);
    }

    public function reserve(): self
    {
        return $this->transitionTo(KeyStatus::Reserved);
    }

    public function release(): self
    {
        return $this->transitionTo(KeyStatus::Available);
    }

    public function sell(): self
    {
        return $this->transitionTo(KeyStatus::Sold);
    }

    /**
     * @param OrderId|null $orderId the order the key was sold to, if known (refund path)
     */
    public function revoke(?OrderId $orderId, \DateTimeImmutable $now): self
    {
        $this->assertCanTransitionTo(KeyStatus::Revoked);

        $version = $this->version + 1;

        return new self(
            $this->id,
            $this->productId,
            $this->code,
            KeyStatus::Revoked,
            $version,
            [...$this->pendingEvents, new KeyRevoked(
                EventId::derive($this->id->value, $version, 'KeyRevoked'),
                $this->id,
                $this->productId,
                $orderId,
                $version,
                $now,
            )],
        );
    }

    private function transitionTo(KeyStatus $to): self
    {
        $this->assertCanTransitionTo($to);

        return new self($this->id, $this->productId, $this->code, $to, $this->version + 1, $this->pendingEvents);
    }

    private function assertCanTransitionTo(KeyStatus $to): void
    {
        if (!$this->status->canTransitionTo($to)) {
            throw new IllegalKeyTransition($this->status, $to);
        }
    }
}
