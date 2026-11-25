<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence;

use App\Inventory\Application\Port\KeyInventory;
use App\Inventory\Domain\Event\KeyRevoked;
use App\Inventory\Domain\KeyStatus;
use App\Inventory\Domain\ProductKey;
use App\Shared\Application\Exception\ConcurrencyConflict;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Identifier\ProductKeyId;
use App\Shared\Domain\KeyCode;
use App\Shared\Infrastructure\Outbox\OutboxWriter;
use App\Shared\Infrastructure\Persistence\DbalTransactionManager;
use App\Shared\Infrastructure\Persistence\Row;
use Doctrine\DBAL\Connection;

/**
 * `product_keys`. `lockAvailable` takes one free key with `FOR UPDATE SKIP LOCKED`: concurrent buyers get different
 * keys and the last key goes to exactly one of them (INV-04, EC-15). Writes are CAS on `version` (stored + 1).
 */
final readonly class DbalKeyInventory implements KeyInventory
{
    public function __construct(
        private Connection $connection,
        private DbalTransactionManager $transactions,
        private OutboxWriter $outbox,
    ) {}

    public function lockAvailable(ProductId $productId): ?ProductKey
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('lockAvailable() must run inside a transaction: the row lock ends with it.');
        }

        $row = $this->connection->fetchAssociative(
            "SELECT id, product_id, code, status, version FROM product_keys
             WHERE product_id = :product_id AND status = 'available'
             ORDER BY id
             LIMIT 1
             FOR UPDATE SKIP LOCKED",
            ['product_id' => $productId->value],
        );

        return $row === false ? null : $this->hydrate(new Row($row));
    }

    public function find(ProductKeyId $id): ?ProductKey
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, product_id, code, status, version FROM product_keys WHERE id = :id',
            ['id' => $id->value],
        );

        return $row === false ? null : $this->hydrate(new Row($row));
    }

    public function save(ProductKey $key): void
    {
        $this->transactions->transactional(function () use ($key): void {
            $updated = $this->connection->executeStatement(
                'UPDATE product_keys SET status = :status, version = :version WHERE id = :id AND version = :expected',
                [
                    'id' => $key->id->value,
                    'status' => $key->status->value,
                    'version' => $key->version,
                    'expected' => $key->version - 1,
                ],
            );

            if ($updated !== 1) {
                throw new ConcurrencyConflict('ProductKey', $key->id->value);
            }

            // Only the event of this transition: the aggregate keeps the events of earlier saves (M8a, see Payment).
            $this->outbox->append(array_values(array_filter(
                $key->pendingEvents,
                static fn(KeyRevoked $event): bool => $event->aggregateVersion() >= $key->version,
            )));
        });
    }

    public function addIfAbsent(ProductKey $key): bool
    {
        return $this->connection->executeStatement(
            'INSERT INTO product_keys (id, product_id, code, status, version) VALUES (:id, :product_id, :code, :status, :version)
             ON CONFLICT DO NOTHING',
            [
                'id' => $key->id->value,
                'product_id' => $key->productId->value,
                'code' => $key->code->reveal(),
                'status' => $key->status->value,
                'version' => $key->version,
            ],
        ) === 1;
    }

    private function hydrate(Row $row): ProductKey
    {
        return ProductKey::reconstitute(
            ProductKeyId::fromString($row->string('id')),
            ProductId::fromString($row->string('product_id')),
            new KeyCode($row->string('code')),
            KeyStatus::from($row->string('status')),
            $row->int('version'),
        );
    }
}
