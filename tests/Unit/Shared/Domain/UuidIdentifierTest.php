<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\Event\EventId;
use App\Shared\Domain\Exception\InvalidIdentifier;
use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Domain\Identifier\OrderId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UuidIdentifierTest extends TestCase
{
    private const VALID_V4 = '3f2504e0-4f89-41d3-9a0c-0305e82c3301';
    private const VALID_V7 = '0190a5c4-7b1e-7a3d-8c2f-1e2d3c4b5a69';

    public function testAcceptsLowerCaseUuids(): void
    {
        self::assertSame(self::VALID_V4, OrderId::fromString(self::VALID_V4)->value);
        self::assertSame(self::VALID_V7, OrderId::fromString(self::VALID_V7)->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'not a uuid' => ['order-1'];
        yield 'upper case' => ['3F2504E0-4F89-41D3-9A0C-0305E82C3301'];
        yield 'nil uuid' => ['00000000-0000-0000-0000-000000000000'];
        yield 'bad variant' => ['3f2504e0-4f89-41d3-ca0c-0305e82c3301'];
        yield 'too short' => ['3f2504e0-4f89-41d3-9a0c-0305e82c330'];
        yield 'trailing newline' => ["3f2504e0-4f89-41d3-9a0c-0305e82c3301\n"];
        yield 'braces' => ['{3f2504e0-4f89-41d3-9a0c-0305e82c3301}'];
    }

    #[DataProvider('invalid')]
    public function testInvalidRejected(string $value): void
    {
        $this->expectException(InvalidIdentifier::class);
        OrderId::fromString($value);
    }

    public function testEqualityRespectsTypeAndValue(): void
    {
        self::assertTrue(OrderId::fromString(self::VALID_V4)->equals(OrderId::fromString(self::VALID_V4)));
        self::assertFalse(OrderId::fromString(self::VALID_V4)->equals(OrderId::fromString(self::VALID_V7)));
        self::assertFalse(OrderId::fromString(self::VALID_V4)->equals(CustomerId::fromString(self::VALID_V4)));
    }

    public function testEventIdIsDeterministicAndValid(): void
    {
        $a = EventId::derive(self::VALID_V4, 3, 'OrderPaid');

        self::assertTrue($a->equals(EventId::derive(self::VALID_V4, 3, 'OrderPaid')));
        self::assertFalse($a->equals(EventId::derive(self::VALID_V4, 4, 'OrderPaid')));
        self::assertFalse($a->equals(EventId::derive(self::VALID_V4, 3, 'OrderCompleted')));
        self::assertSame(1, preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $a->value));
    }
}
