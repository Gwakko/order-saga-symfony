<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

/**
 * Every timestamp in a response: ISO-8601, UTC, milliseconds, explicit `+00:00` (EC-76, INV-12).
 */
final class ApiDateTime
{
    public const string FORMAT = 'Y-m-d\TH:i:s.vP';

    public static function format(\DateTimeInterface $at): string
    {
        return \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('UTC'))->format(self::FORMAT);
    }

    public static function formatNullable(?\DateTimeInterface $at): ?string
    {
        return $at === null ? null : self::format($at);
    }
}
