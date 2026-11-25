<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application;

use App\Shared\Application\Command\PurgeIdempotencyKeys\PurgeIdempotencyKeysCommand;
use App\Shared\Application\Command\PurgeIdempotencyKeys\PurgeIdempotencyKeysHandler;
use App\Shared\Application\Exception\InvalidInput;
use App\Shared\Application\Port\IdempotencyKeyCleaner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[Group('M8a')]
final class PurgeIdempotencyKeysHandlerTest extends TestCase
{
    #[Group('T-M8A-34')]
    public function testPassesTheClockAndTheLimitToTheCleaner(): void
    {
        $cleaner = new class implements IdempotencyKeyCleaner {
            public ?\DateTimeImmutable $now = null;
            public int $limit = 0;

            public function deleteExpired(\DateTimeImmutable $now, int $limit): int
            {
                $this->now = $now;
                $this->limit = $limit;

                return 7;
            }
        };

        $result = (new PurgeIdempotencyKeysHandler($cleaner, new MockClock('2026-05-01T10:00:00Z')))(new PurgeIdempotencyKeysCommand(500));

        self::assertSame(7, $result->deleted);
        self::assertSame(500, $cleaner->limit);
        self::assertEquals(new \DateTimeImmutable('2026-05-01T10:00:00Z'), $cleaner->now);
    }

    #[Group('T-M8A-34')]
    public function testLimitIsValidated(): void
    {
        $this->expectException(InvalidInput::class);

        new PurgeIdempotencyKeysCommand(0);
    }
}
