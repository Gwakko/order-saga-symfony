<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\Http\ApiDateTime;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * EC-76: ISO-8601, UTC, milliseconds, explicit +00:00.
 */
#[Group('M5.1')]
final class ApiDateTimeTest extends TestCase
{
    public function testUtcInstantKeepsMillisecondsOnly(): void
    {
        self::assertSame('2026-05-01T10:00:00.123+00:00', ApiDateTime::format(new \DateTimeImmutable('2026-05-01T10:00:00.123987Z')));
    }

    public function testOtherZonesAreConvertedToUtc(): void
    {
        self::assertSame('2026-05-01T07:00:00.000+00:00', ApiDateTime::format(new \DateTimeImmutable('2026-05-01T10:00:00+03:00')));
        self::assertSame('2026-05-01T10:00:00.000+00:00', ApiDateTime::format(new \DateTimeImmutable('2026-05-01 06:00:00', new \DateTimeZone('America/New_York'))));
    }

    public function testMutableDateTimeWorksToo(): void
    {
        self::assertSame('2026-01-02T03:04:05.000+00:00', ApiDateTime::format(new \DateTime('2026-01-02T03:04:05Z')));
    }

    public function testNullStaysNull(): void
    {
        self::assertNull(ApiDateTime::formatNullable(null));
        self::assertSame('2026-01-02T03:04:05.000+00:00', ApiDateTime::formatNullable(new \DateTimeImmutable('2026-01-02T03:04:05Z')));
    }
}
