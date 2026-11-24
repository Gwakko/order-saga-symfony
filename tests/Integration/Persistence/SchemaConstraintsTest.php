<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\Group;

/**
 * M4.1: the database itself enforces INV-04, INV-05 and INV-06 (partial unique indexes, unique `payments(order_id)`).
 */
#[Group('M4.1')]
final class SchemaConstraintsTest extends IntegrationTestCase
{
    public function testSecondActiveReservationOfOneKeyIsRejected(): void
    {
        $key = self::uuid();
        $this->reservation(self::uuid(), $key, 'active');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->reservation(self::uuid(), $key, 'active');
    }

    public function testSecondLiveReservationOfOneOrderIsRejected(): void
    {
        $order = self::uuid();
        $this->reservation($order, self::uuid(), 'consumed');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->reservation($order, self::uuid(), 'active');
    }

    public function testReleasedAndExpiredReservationsDoNotBlockNewOnes(): void
    {
        $order = self::uuid();
        $key = self::uuid();
        $this->reservation($order, $key, 'released');
        $this->reservation($order, $key, 'expired');
        $this->reservation($order, $key, 'active');

        self::assertSame(3, $this->countRows('key_reservations'));
    }

    public function testOnePaymentPerOrder(): void
    {
        $order = self::uuid();
        $this->payment($order);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->payment($order);
    }

    public function testNoForeignKeysExist(): void
    {
        self::assertSame(0, $this->countRows('information_schema.table_constraints', "constraint_type = 'FOREIGN KEY' AND table_schema = 'public'"));
    }

    private function reservation(string $orderId, string $keyId, string $status): void
    {
        $this->connection->executeStatement(
            'INSERT INTO key_reservations (id, order_id, key_id, product_id, status, created_at, expires_at, version)
             VALUES (:id, :order_id, :key_id, :product_id, :status, now(), now() + interval \'15 minutes\', 1)',
            ['id' => self::uuid(), 'order_id' => $orderId, 'key_id' => $keyId, 'product_id' => self::uuid(), 'status' => $status],
        );
    }

    private function payment(string $orderId): void
    {
        $this->connection->executeStatement(
            'INSERT INTO payments (id, order_id, amount, currency, status, attempts, created_at, updated_at, version)
             VALUES (:id, :order_id, 1999, \'EUR\', \'pending\', 0, now(), now(), 1)',
            ['id' => self::uuid(), 'order_id' => $orderId],
        );
    }
}
