<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Domain;

use App\Inventory\Domain\KeyReservation;
use App\Inventory\Domain\ProductKey;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Identifier\ProductKeyId;
use App\Shared\Domain\Identifier\ReservationId;
use App\Shared\Domain\KeyCode;

final class InventoryFactory
{
    public const RESERVATION_ID = '0190a5c4-1000-7000-8000-000000000001';
    public const ORDER_ID = '0190a5c4-1000-7000-8000-000000000002';
    public const OTHER_ORDER_ID = '0190a5c4-1000-7000-8000-0000000000b2';
    public const KEY_ID = '0190a5c4-1000-7000-8000-000000000003';
    public const PRODUCT_ID = '0190a5c4-1000-7000-8000-000000000004';
    public const TTL = 900;

    public static function t0(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-05-01T10:00:00Z');
    }

    public static function at(int $secondsAfterCreation): \DateTimeImmutable
    {
        return self::t0()->modify(sprintf('+%d seconds', $secondsAfterCreation));
    }

    public static function reservation(): KeyReservation
    {
        return KeyReservation::create(
            ReservationId::fromString(self::RESERVATION_ID),
            OrderId::fromString(self::ORDER_ID),
            ProductKeyId::fromString(self::KEY_ID),
            ProductId::fromString(self::PRODUCT_ID),
            self::t0(),
            self::TTL,
        );
    }

    public static function key(): ProductKey
    {
        return ProductKey::add(
            ProductKeyId::fromString(self::KEY_ID),
            ProductId::fromString(self::PRODUCT_ID),
            new KeyCode('ABCDE-FGHIJ-AB12'),
        );
    }
}
