<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\Exception\InvalidRefundWindow;
use App\Shared\Domain\RefundWindow;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class RefundWindowTest extends TestCase
{
    private const FOURTEEN_DAYS = 14 * 86400;

    #[Group('T-U-09')]
    public function testDefaultIsFourteenDays(): void
    {
        self::assertSame(self::FOURTEEN_DAYS, (new RefundWindow())->seconds);
    }

    #[Group('T-U-09')]
    public function testBoundaryIsInclusive(): void
    {
        $window = new RefundWindow();
        $completedAt = new \DateTimeImmutable('2026-05-01T10:00:00Z');

        self::assertTrue($window->isOpen($completedAt, $completedAt));
        self::assertTrue($window->isOpen($completedAt, $completedAt->modify('+' . (self::FOURTEEN_DAYS - 1) . ' seconds')));
        self::assertTrue($window->isOpen($completedAt, $completedAt->modify('+' . self::FOURTEEN_DAYS . ' seconds')));
        self::assertFalse($window->isOpen($completedAt, $completedAt->modify('+' . (self::FOURTEEN_DAYS + 1) . ' seconds')));
    }

    #[Group('T-U-09')]
    public function testCustomWindow(): void
    {
        $window = new RefundWindow(60);
        $completedAt = new \DateTimeImmutable('2026-05-01T10:00:00Z');

        self::assertTrue($window->isOpen($completedAt, $completedAt->modify('+60 seconds')));
        self::assertFalse($window->isOpen($completedAt, $completedAt->modify('+61 seconds')));
    }

    #[Group('T-U-09')]
    public function testNonPositiveWindowRejected(): void
    {
        $this->expectException(InvalidRefundWindow::class);
        new RefundWindow(0);
    }

    /**
     * EC-75: Europe/Kyiv switches to summer time on the last Sunday of March (2026-03-29 03:00 -> 04:00).
     * The window counts elapsed UTC seconds, never wall-clock days.
     */
    #[Group('T-U-11')]
    public function testDstIndependent(): void
    {
        $kyiv = new \DateTimeZone('Europe/Kyiv');
        $window = new RefundWindow();
        $completedAt = new \DateTimeImmutable('2026-03-20 12:00:00', $kyiv);

        // The same wall-clock moment 14 "calendar days" later lies 14 days minus one hour (DST) away in real time.
        $wallClockPlus14Days = new \DateTimeImmutable('2026-04-03 12:00:00', $kyiv);
        self::assertSame(self::FOURTEEN_DAYS - 3600, $wallClockPlus14Days->getTimestamp() - $completedAt->getTimestamp());
        self::assertTrue($window->isOpen($completedAt, $wallClockPlus14Days));

        // 14 days + 1 second elapsed is closed no matter how the instants are expressed.
        $closedInstant = $completedAt->setTimestamp($completedAt->getTimestamp() + self::FOURTEEN_DAYS + 1);
        self::assertFalse($window->isOpen($completedAt, $closedInstant));
        self::assertFalse($window->isOpen($completedAt, $closedInstant->setTimezone(new \DateTimeZone('UTC'))));
        self::assertFalse($window->isOpen($completedAt->setTimezone(new \DateTimeZone('UTC')), $closedInstant));

        $openInstant = $completedAt->setTimestamp($completedAt->getTimestamp() + self::FOURTEEN_DAYS);
        self::assertTrue($window->isOpen($completedAt, $openInstant));
        self::assertTrue($window->isOpen($completedAt->setTimezone(new \DateTimeZone('UTC')), $openInstant->setTimezone(new \DateTimeZone('America/New_York'))));
    }
}
