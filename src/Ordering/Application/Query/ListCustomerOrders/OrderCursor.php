<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\ListCustomerOrders;

use App\Ordering\Application\Exception\InvalidCursor;
use App\Shared\Domain\Exception\InvalidIdentifier;
use App\Shared\Domain\Identifier\OrderId;

/**
 * Keyset position in the list `created_at DESC, order_id DESC`. Wire form: base64url(`created_at|order_id`)
 * without padding, `created_at` in UTC with microseconds.
 */
final readonly class OrderCursor
{
    private const string FORMAT = 'Y-m-d\TH:i:s.uP';

    public function __construct(public \DateTimeImmutable $createdAt, public OrderId $orderId) {}

    public function encode(): string
    {
        $raw = $this->createdAt->setTimezone(new \DateTimeZone('UTC'))->format(self::FORMAT) . '|' . $this->orderId->value;

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @throws InvalidCursor
     */
    public static function decode(string $cursor): self
    {
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($raw === false || substr_count($raw, '|') !== 1) {
            throw new InvalidCursor();
        }

        [$time, $id] = explode('|', $raw);
        $createdAt = \DateTimeImmutable::createFromFormat('!' . self::FORMAT, $time);

        if ($createdAt === false || $createdAt->format(self::FORMAT) !== $time) {
            throw new InvalidCursor();
        }

        try {
            return new self($createdAt, OrderId::fromString($id));
        } catch (InvalidIdentifier) {
            throw new InvalidCursor();
        }
    }
}
