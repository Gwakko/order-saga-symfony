<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Whole schema in one step. Table ownership follows 00_overview.md section 5.2; there are no foreign keys at all, so
 * none can cross a module boundary (T-ARCH-04). References between modules are plain uuid columns.
 * All timestamps are `timestamptz`; money is `bigint` minor units plus a 3-letter currency code.
 */
final class Version20261124100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: ordering, inventory, payment, outbox, inbox, idempotency, read models';
    }

    public function up(Schema $schema): void
    {
        // Ordering
        $this->addSql(<<<'SQL'
            CREATE TABLE orders (
                id uuid NOT NULL,
                customer_id uuid NOT NULL,
                product_id uuid NOT NULL,
                price_amount bigint NOT NULL,
                price_currency char(3) NOT NULL,
                status varchar(32) NOT NULL,
                reservation_id uuid DEFAULT NULL,
                payment_id uuid DEFAULT NULL,
                delivered_key_id uuid DEFAULT NULL,
                failure_reason varchar(32) DEFAULT NULL,
                compensation_reason varchar(32) DEFAULT NULL,
                cancellation_requested boolean NOT NULL DEFAULT false,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                completed_at timestamptz DEFAULT NULL,
                version integer NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT orders_version_positive CHECK (version >= 1),
                CONSTRAINT orders_price_range CHECK (price_amount > 0 AND price_amount <= 100000000)
            )
            SQL);
        $this->addSql('CREATE INDEX orders_customer_idx ON orders (customer_id, created_at DESC, id DESC)');

        $this->addSql(<<<'SQL'
            CREATE TABLE order_view (
                order_id uuid NOT NULL,
                customer_id uuid NOT NULL,
                product_id uuid NOT NULL,
                status varchar(32) NOT NULL,
                price_amount bigint NOT NULL,
                price_currency char(3) NOT NULL,
                failure_reason varchar(32) DEFAULT NULL,
                cancellation_requested boolean NOT NULL DEFAULT false,
                delivered_key_id uuid DEFAULT NULL,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                completed_at timestamptz DEFAULT NULL,
                version integer NOT NULL,
                PRIMARY KEY (order_id)
            )
            SQL);
        $this->addSql('CREATE INDEX order_view_customer_idx ON order_view (customer_id, created_at DESC, order_id DESC)');
        $this->addSql('CREATE INDEX order_view_customer_status_idx ON order_view (customer_id, status, created_at DESC, order_id DESC)');

        // Inventory
        $this->addSql(<<<'SQL'
            CREATE TABLE products (
                id uuid NOT NULL,
                name varchar(200) NOT NULL,
                price_amount bigint NOT NULL,
                price_currency char(3) NOT NULL,
                active boolean NOT NULL DEFAULT true,
                PRIMARY KEY (id),
                CONSTRAINT products_price_positive CHECK (price_amount > 0 AND price_amount <= 100000000)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE product_keys (
                id uuid NOT NULL,
                product_id uuid NOT NULL,
                code varchar(64) NOT NULL,
                status varchar(16) NOT NULL,
                version integer NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT product_keys_code_unique UNIQUE (product_id, code),
                CONSTRAINT product_keys_status_valid CHECK (status IN ('available', 'reserved', 'sold', 'revoked'))
            )
            SQL);
        $this->addSql("CREATE INDEX product_keys_available_idx ON product_keys (product_id, id) WHERE status = 'available'");

        $this->addSql(<<<'SQL'
            CREATE TABLE key_reservations (
                id uuid NOT NULL,
                order_id uuid NOT NULL,
                key_id uuid NOT NULL,
                product_id uuid NOT NULL,
                status varchar(16) NOT NULL,
                created_at timestamptz NOT NULL,
                expires_at timestamptz NOT NULL,
                version integer NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT key_reservations_status_valid CHECK (status IN ('active', 'consumed', 'released', 'expired'))
            )
            SQL);
        // INV-04: a key has at most one active reservation.
        $this->addSql("CREATE UNIQUE INDEX key_reservations_one_active_per_key ON key_reservations (key_id) WHERE status = 'active'");
        // INV-05: an order has at most one active or consumed reservation.
        $this->addSql("CREATE UNIQUE INDEX key_reservations_one_live_per_order ON key_reservations (order_id) WHERE status IN ('active', 'consumed')");
        $this->addSql('CREATE INDEX key_reservations_order_idx ON key_reservations (order_id, created_at DESC)');
        $this->addSql('CREATE INDEX key_reservations_key_idx ON key_reservations (key_id, created_at DESC)');
        $this->addSql("CREATE INDEX key_reservations_expiry_idx ON key_reservations (expires_at) WHERE status = 'active'");

        $this->addSql(<<<'SQL'
            CREATE TABLE product_availability_view (
                product_id uuid NOT NULL,
                name varchar(200) NOT NULL,
                price_amount bigint NOT NULL,
                price_currency char(3) NOT NULL,
                available integer NOT NULL DEFAULT 0,
                updated_at timestamptz NOT NULL,
                PRIMARY KEY (product_id),
                CONSTRAINT product_availability_view_available_non_negative CHECK (available >= 0)
            )
            SQL);

        // Payment
        $this->addSql(<<<'SQL'
            CREATE TABLE payments (
                id uuid NOT NULL,
                order_id uuid NOT NULL,
                amount bigint NOT NULL,
                currency char(3) NOT NULL,
                status varchar(16) NOT NULL,
                gateway_charge_id varchar(255) DEFAULT NULL,
                gateway_refund_id varchar(255) DEFAULT NULL,
                attempts integer NOT NULL DEFAULT 0,
                last_error text DEFAULT NULL,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                version integer NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT payments_order_unique UNIQUE (order_id),
                CONSTRAINT payments_amount_positive CHECK (amount > 0 AND amount <= 100000000),
                CONSTRAINT payments_status_valid CHECK (status IN ('pending', 'unknown', 'succeeded', 'declined', 'abandoned', 'refund_pending', 'refunded'))
            )
            SQL);
        $this->addSql("CREATE INDEX payments_open_idx ON payments (updated_at) WHERE status IN ('unknown', 'abandoned', 'refund_pending')");

        // Shared
        $this->addSql(<<<'SQL'
            CREATE TABLE outbox_messages (
                id uuid NOT NULL,
                aggregate_type varchar(64) NOT NULL,
                aggregate_id uuid NOT NULL,
                aggregate_version integer NOT NULL,
                event_type varchar(128) NOT NULL,
                payload jsonb NOT NULL,
                occurred_at timestamptz NOT NULL,
                published_at timestamptz DEFAULT NULL,
                attempts integer NOT NULL DEFAULT 0,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX outbox_messages_unpublished_idx ON outbox_messages (occurred_at, aggregate_version, id) WHERE published_at IS NULL');
        $this->addSql('CREATE INDEX outbox_messages_aggregate_idx ON outbox_messages (aggregate_id, aggregate_version)');

        $this->addSql(<<<'SQL'
            CREATE TABLE inbox_messages (
                consumer varchar(128) NOT NULL,
                message_id uuid NOT NULL,
                processed_at timestamptz NOT NULL,
                PRIMARY KEY (consumer, message_id)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE idempotency_keys (
                customer_id uuid NOT NULL,
                key varchar(255) NOT NULL,
                request_hash char(64) NOT NULL,
                method varchar(10) NOT NULL,
                path varchar(255) NOT NULL,
                status varchar(16) NOT NULL,
                response_status integer DEFAULT NULL,
                response_body jsonb DEFAULT NULL,
                created_at timestamptz NOT NULL,
                expires_at timestamptz NOT NULL,
                PRIMARY KEY (customer_id, key),
                CONSTRAINT idempotency_keys_status_valid CHECK (status IN ('in_progress', 'completed'))
            )
            SQL);
        $this->addSql('CREATE INDEX idempotency_keys_expiry_idx ON idempotency_keys (expires_at)');
    }

    public function down(Schema $schema): void
    {
        foreach ([
            'idempotency_keys',
            'inbox_messages',
            'outbox_messages',
            'payments',
            'product_availability_view',
            'key_reservations',
            'product_keys',
            'products',
            'order_view',
            'orders',
        ] as $table) {
            $this->addSql(sprintf('DROP TABLE %s', $table));
        }
    }
}
