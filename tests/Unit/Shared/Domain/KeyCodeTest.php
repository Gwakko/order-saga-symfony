<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\Exception\InvalidKeyCode;
use App\Shared\Domain\KeyCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class KeyCodeTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function codes(): iterable
    {
        yield 'minimum length' => ['AB12C'];
        yield 'typical' => ['ABCDE-FGHIJ-KLMNO-12345'];
        yield 'maximum length' => [str_repeat('A', 64)];
    }

    #[Group('T-U-15')]
    #[DataProvider('codes')]
    public function testMaskedNeverExposesFullCode(string $raw): void
    {
        $key = new KeyCode($raw);
        $masked = $key->masked();

        self::assertNotSame($raw, $masked);
        self::assertStringNotContainsString($raw, $masked);
        self::assertSame('XXXX-...-' . substr($raw, -4), $masked);
        // Only the last four characters may be visible: the part before them must be fully hidden.
        self::assertSame(substr($raw, -4), substr($masked, -4));
        self::assertStringNotContainsString(substr($raw, 0, 5), str_replace(substr($raw, -4), '', $masked));
    }

    #[Group('T-U-15')]
    public function testHasNoStringConversion(): void
    {
        self::assertFalse((new \ReflectionClass(KeyCode::class))->hasMethod('__toString'));
    }

    #[Group('T-U-15')]
    public function testRevealReturnsOriginal(): void
    {
        self::assertSame('ABCDE-12345', (new KeyCode('ABCDE-12345'))->reveal());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCodes(): iterable
    {
        yield 'too short' => ['AB12'];
        yield 'too long' => [str_repeat('A', 65)];
        yield 'lower case' => ['abcde-12345'];
        yield 'space' => ['ABCDE 12345'];
        yield 'underscore' => ['ABCDE_12345'];
        yield 'empty' => [''];
        yield 'trailing newline' => ["ABCDE-12345\n"];
    }

    #[DataProvider('invalidCodes')]
    public function testInvalidFormatRejected(string $raw): void
    {
        $this->expectException(InvalidKeyCode::class);
        new KeyCode($raw);
    }

    public function testEquals(): void
    {
        self::assertTrue((new KeyCode('ABCDE-12345'))->equals(new KeyCode('ABCDE-12345')));
        self::assertFalse((new KeyCode('ABCDE-12345'))->equals(new KeyCode('ABCDE-12346')));
    }
}
