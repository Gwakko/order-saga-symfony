<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use App\Shared\Domain\Exception\InvalidRefundWindow;

/**
 * Refund window measured in elapsed seconds between two instants, so it is independent of time zones and DST.
 * The boundary is inclusive: exactly `seconds` after completion the window is still open.
 */
final readonly class RefundWindow
{
    public const DEFAULT_SECONDS = 14 * 86400;

    public function __construct(public int $seconds = self::DEFAULT_SECONDS)
    {
        if ($seconds <= 0) {
            throw new InvalidRefundWindow($seconds);
        }
    }

    public function isOpen(\DateTimeImmutable $completedAt, \DateTimeImmutable $now): bool
    {
        return $now->getTimestamp() - $completedAt->getTimestamp() <= $this->seconds;
    }
}
