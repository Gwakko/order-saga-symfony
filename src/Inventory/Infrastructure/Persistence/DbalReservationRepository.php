<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence;

use App\Inventory\Application\Port\ReservationRepository;
use App\Inventory\Domain\KeyReservation;
use App\Inventory\Domain\ReservationStatus;
use App\Shared\Application\Exception\ConcurrencyConflict;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Identifier\ProductKeyId;
use App\Shared\Domain\Identifier\ReservationId;
use App\Shared\Infrastructure\Outbox\OutboxWriter;
use App\Shared\Infrastructure\Persistence\DbalTransactionManager;
use App\Shared\Infrastructure\Persistence\DbTimestamp;
use App\Shared\Infrastructure\Persistence\Row;
use Doctrine\DBAL\Connection;

/**
 * `key_reservations`. Version 1 is an INSERT, anything else a CAS on `version - 1`. Two partial unique indexes back
 * INV-04 and INV-05; `ON CONFLICT DO NOTHING` turns a violation into zero written rows (a violation would abort the
 * surrounding Postgres transaction), which surfaces as ConcurrencyConflict.
 */
final readonly class DbalReservationRepository implements ReservationRepository
{
    private const string COLUMNS = 'id, order_id, key_id, product_id, status, created_at, expires_at, version';

    public function __construct(
        private Connection $connection,
        private DbalTransactionManager $transactions,
        private OutboxWriter $outbox,
    ) {}

    public function findByOrder(OrderId $orderId): ?KeyReservation
    {
        $row = $this->connection->fetchAssociative(
            "SELECT " . self::COLUMNS . " FROM key_reservations WHERE order_id = :order_id
             ORDER BY (status IN ('active', 'consumed')) DESC, created_at DESC, id DESC
             LIMIT 1",
            ['order_id' => $orderId->value],
        );

        return $row === false ? null : $this->hydrate(new Row($row));
    }

    public function findByKey(ProductKeyId $keyId): ?KeyReservation
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM key_reservations WHERE key_id = :key_id ORDER BY created_at DESC, id DESC LIMIT 1',
            ['key_id' => $keyId->value],
        );

        return $row === false ? null : $this->hydrate(new Row($row));
    }

    public function findExpired(\DateTimeImmutable $now, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT " . self::COLUMNS . " FROM key_reservations
             WHERE status = 'active' AND expires_at <= :now
             ORDER BY expires_at, id
             LIMIT :limit",
            ['now' => DbTimestamp::format($now), 'limit' => $limit],
        );

        return array_map(fn(array $row): KeyReservation => $this->hydrate(new Row($row)), $rows);
    }

    public function save(KeyReservation $reservation): void
    {
        $this->transactions->transactional(function () use ($reservation): void {
            $written = $reservation->version === 1 ? $this->insert($reservation) : $this->update($reservation);

            if ($written !== 1) {
                throw new ConcurrencyConflict('KeyReservation', $reservation->id->value);
            }

            $this->outbox->append($reservation->pendingEvents);
        });
    }

    private function insert(KeyReservation $reservation): int
    {
        return (int) $this->connection->executeStatement(
            'INSERT INTO key_reservations (id, order_id, key_id, product_id, status, created_at, expires_at, version)
             VALUES (:id, :order_id, :key_id, :product_id, :status, :created_at, :expires_at, :version)
             ON CONFLICT DO NOTHING',
            [
                'id' => $reservation->id->value,
                'order_id' => $reservation->orderId->value,
                'key_id' => $reservation->keyId->value,
                'product_id' => $reservation->productId->value,
                'status' => $reservation->status->value,
                'created_at' => DbTimestamp::format($reservation->createdAt),
                'expires_at' => DbTimestamp::format($reservation->expiresAt),
                'version' => $reservation->version,
            ],
        );
    }

    private function update(KeyReservation $reservation): int
    {
        return (int) $this->connection->executeStatement(
            'UPDATE key_reservations SET status = :status, version = :version WHERE id = :id AND version = :expected',
            [
                'id' => $reservation->id->value,
                'status' => $reservation->status->value,
                'version' => $reservation->version,
                'expected' => $reservation->version - 1,
            ],
        );
    }

    private function hydrate(Row $row): KeyReservation
    {
        return KeyReservation::reconstitute(
            ReservationId::fromString($row->string('id')),
            OrderId::fromString($row->string('order_id')),
            ProductKeyId::fromString($row->string('key_id')),
            ProductId::fromString($row->string('product_id')),
            ReservationStatus::from($row->string('status')),
            $row->time('created_at'),
            $row->time('expires_at'),
            $row->int('version'),
        );
    }
}
