<?php

declare(strict_types=1);

namespace App\Inventory\Application\Port;

use App\Inventory\Domain\ProductKey;
use App\Shared\Application\Exception\ConcurrencyConflict;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Identifier\ProductKeyId;

interface KeyInventory
{
    /**
     * Picks one `Available` key of the product and locks it (`FOR UPDATE SKIP LOCKED`); null when none is left.
     * Must be called inside a transaction.
     */
    public function lockAvailable(ProductId $productId): ?ProductKey;

    public function find(ProductKeyId $id): ?ProductKey;

    /**
     * CAS on the key version.
     *
     * @throws ConcurrencyConflict
     */
    public function save(ProductKey $key): void;

    /**
     * Stores a new key unless the same code already exists for the product. Returns false for a duplicate.
     */
    public function addIfAbsent(ProductKey $key): bool;
}
