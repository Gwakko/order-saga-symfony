<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Idempotency;

use App\Shared\Infrastructure\Idempotency\Exception\IdempotencyKeyInvalid;
use App\Shared\Infrastructure\Idempotency\Exception\IdempotencyKeyMissing;
use App\Shared\Infrastructure\Idempotency\IdempotencyKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('M5.2')]
final class IdempotencyKeyTest extends TestCase
{
    public function testAbsentHeaderIsMissing(): void
    {
        $this->expectException(IdempotencyKeyMissing::class);

        IdempotencyKey::fromHeader(null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield '256 characters' => [str_repeat('a', 256)];
        yield 'slash' => ['a/b'];
        yield 'space' => ['a b'];
        yield 'colon' => ['a:b'];
        yield 'newline at the end' => ["abc\n"];
        yield 'multibyte' => ['ключ'];
        yield 'null byte' => ["a\0b"];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalid(string $header): void
    {
        $this->expectException(IdempotencyKeyInvalid::class);

        IdempotencyKey::fromHeader($header);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function valid(): iterable
    {
        yield 'single' => ['a'];
        yield 'max length' => [str_repeat('Z', 255)];
        yield 'symbols' => ['A_b-9'];
        yield 'uuid' => ['0190a5c4-0000-7000-8000-000000000001'];
    }

    #[DataProvider('valid')]
    public function testAcceptsValid(string $header): void
    {
        self::assertSame($header, IdempotencyKey::fromHeader($header)->value);
    }
}
