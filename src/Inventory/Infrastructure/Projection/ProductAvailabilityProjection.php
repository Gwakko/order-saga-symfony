<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Projection;

use App\Inventory\Domain\Event\KeyRevoked;
use App\Inventory\Domain\Event\ReservationEvent;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Infrastructure\Inbox\InboxGuard;
use App\Shared\Infrastructure\Outbox\DomainEventCodec;
use App\Shared\Infrastructure\Outbox\OutboxMessage;
use App\Shared\Infrastructure\Persistence\DbTimestamp;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Keeps `product_availability_view` in step with the stock. Every inventory event recounts the product's available
 * keys and upserts the row, so the result does not depend on the order or the number of deliveries (a recount is
 * idempotent). `refresh()` does the same for a product without an event (new product, keys added).
 */
#[AsMessageHandler]
final readonly class ProductAvailabilityProjection
{
    public const string CONSUMER = 'product_availability_projection';

    public function __construct(
        private Connection $connection,
        private InboxGuard $inbox,
        private DomainEventCodec $codec,
        private ClockInterface $clock,
    ) {}

    public function __invoke(OutboxMessage $message): void
    {
        if ($message->aggregateType !== 'reservation' && $message->aggregateType !== 'key') {
            return;
        }

        $event = $this->codec->toEvent($message);

        $productId = match (true) {
            $event instanceof ReservationEvent, $event instanceof KeyRevoked => $event->productId,
            default => throw new \UnexpectedValueException(sprintf('%s is not an inventory event.', $event::class)),
        };

        $this->inbox->once(self::CONSUMER, $message->messageId, fn() => $this->refresh($productId));
    }

    /**
     * Recounts one product; a product that does not exist (or is inactive) has no row.
     */
    public function refresh(ProductId $productId): void
    {
        $this->connection->executeStatement(
            "INSERT INTO product_availability_view (product_id, name, price_amount, price_currency, available, updated_at)
             SELECT p.id, p.name, p.price_amount, p.price_currency,
                    (SELECT count(*) FROM product_keys k WHERE k.product_id = p.id AND k.status = 'available'),
                    :now
             FROM products p
             WHERE p.id = :product_id
             ON CONFLICT (product_id) DO UPDATE SET
                 name = EXCLUDED.name,
                 price_amount = EXCLUDED.price_amount,
                 price_currency = EXCLUDED.price_currency,
                 available = EXCLUDED.available,
                 updated_at = EXCLUDED.updated_at",
            [
                'product_id' => $productId->value,
                'now' => DbTimestamp::format(\DateTimeImmutable::createFromInterface($this->clock->now())),
            ],
        );
    }
}
