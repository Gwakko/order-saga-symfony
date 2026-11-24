<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Inventory\Application\Port\KeyInventory;
use App\Inventory\Domain\KeyStatus;
use App\Inventory\Domain\ProductKey;
use App\Shared\Application\Exception\ConcurrencyConflict;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Identifier\ProductKeyId;

final class InMemoryKeyInventory implements KeyInventory
{
    /** @var array<string, ProductKey> */
    private array $keys = [];

    public function lockAvailable(ProductId $productId): ?ProductKey
    {
        foreach ($this->keys as $key) {
            if ($key->productId->equals($productId) && $key->status === KeyStatus::Available) {
                return $key;
            }
        }

        return null;
    }

    public function find(ProductKeyId $id): ?ProductKey
    {
        return $this->keys[$id->value] ?? null;
    }

    public function save(ProductKey $key): void
    {
        $stored = $this->keys[$key->id->value] ?? null;

        if ($stored === null || $stored->version + 1 !== $key->version) {
            throw new ConcurrencyConflict('ProductKey', $key->id->value);
        }

        $this->keys[$key->id->value] = self::detach($key);
    }

    public function addIfAbsent(ProductKey $key): bool
    {
        foreach ($this->keys as $existing) {
            if ($existing->productId->equals($key->productId) && $existing->code->equals($key->code)) {
                return false;
            }
        }

        $this->keys[$key->id->value] = self::detach($key);

        return true;
    }

    public function seed(ProductKey $key): void
    {
        $this->keys[$key->id->value] = self::detach($key);
    }

    public function count(): int
    {
        return count($this->keys);
    }

    private static function detach(ProductKey $key): ProductKey
    {
        return ProductKey::reconstitute($key->id, $key->productId, $key->code, $key->status, $key->version);
    }
}
