<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Domain;

use App\Inventory\Domain\Exception\IllegalKeyTransition;
use App\Inventory\Domain\KeyStatus;
use App\Inventory\Domain\ProductKey;
use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;
use App\Shared\Domain\Identifier\OrderId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * T-U-13: KeyStatus transition table.
 */
#[Group('T-U-13')]
final class ProductKeyTest extends TestCase
{
    /**
     * @return array<string, ProductKey>
     */
    private static function inEveryStatus(): array
    {
        $available = InventoryFactory::key();
        $reserved = $available->reserve();

        return [
            'available' => $available,
            'reserved' => $reserved,
            'sold' => $reserved->sell(),
            'revoked' => $available->revoke(null, InventoryFactory::t0()),
        ];
    }

    /**
     * @return iterable<string, array{KeyStatus, KeyStatus, bool}>
     */
    public static function transitionTable(): iterable
    {
        $allowed = [
            'available' => ['reserved', 'revoked'],
            'reserved' => ['available', 'sold', 'revoked'],
            'sold' => ['revoked'],
            'revoked' => [],
        ];

        foreach (KeyStatus::cases() as $from) {
            foreach (KeyStatus::cases() as $to) {
                yield $from->value . ' -> ' . $to->value => [$from, $to, in_array($to->value, $allowed[$from->value], true)];
            }
        }
    }

    #[DataProvider('transitionTable')]
    public function testStatusTableMatchesSpec(KeyStatus $from, KeyStatus $to, bool $expected): void
    {
        self::assertSame($expected, $from->canTransitionTo($to));
    }

    #[DataProvider('transitionTable')]
    public function testAggregateMatchesSpec(KeyStatus $from, KeyStatus $to, bool $expected): void
    {
        $key = self::inEveryStatus()[$from->value];

        $attempt = match ($to) {
            KeyStatus::Available => fn(): ProductKey => $key->release(),
            KeyStatus::Reserved => fn(): ProductKey => $key->reserve(),
            KeyStatus::Sold => fn(): ProductKey => $key->sell(),
            KeyStatus::Revoked => fn(): ProductKey => $key->revoke(null, InventoryFactory::t0()),
        };

        try {
            $result = $attempt();
        } catch (DomainError $e) {
            self::assertFalse($expected, 'spec allows this transition but the aggregate refused it');
            self::assertInstanceOf(IllegalKeyTransition::class, $e);
            self::assertSame(DomainErrorCode::IllegalKeyTransition, $e->code());

            return;
        }

        self::assertTrue($expected, 'spec forbids this transition but the aggregate allowed it');
        self::assertSame($to, $result->status);
        self::assertSame($key->version + 1, $result->version);
        self::assertSame($from, $key->status);
    }

    public function testNewKeyIsAvailableAtVersionOne(): void
    {
        $key = InventoryFactory::key();

        self::assertSame(KeyStatus::Available, $key->status);
        self::assertSame(1, $key->version);
        self::assertSame([], $key->pendingEvents);
    }

    public function testRevokeRaisesKeyRevokedEventWithoutExposingTheCode(): void
    {
        $sold = InventoryFactory::key()->reserve()->sell();
        $revoked = $sold->revoke(OrderId::fromString(InventoryFactory::ORDER_ID), InventoryFactory::at(5));

        self::assertCount(1, $revoked->pendingEvents);
        $event = $revoked->pendingEvents[0];
        self::assertSame($revoked->version, $event->aggregateVersion());
        self::assertSame(InventoryFactory::ORDER_ID, $event->orderId?->value);
        self::assertStringNotContainsString('ABCDE', serialize($event));
    }

    public function testRevokedKeyCannotBeRevokedAgain(): void
    {
        $revoked = InventoryFactory::key()->revoke(null, InventoryFactory::t0());

        $this->expectException(IllegalKeyTransition::class);
        $revoked->revoke(null, InventoryFactory::t0());
    }
}
