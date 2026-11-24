<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence;

/**
 * `timestamptz` <-> UTC `DateTimeImmutable`. Writes always carry an explicit offset and reads normalise to UTC, so
 * results do not depend on the PHP default timezone or the Postgres session timezone (EC-74, INV-12).
 */
final class DbTimestamp
{
    private const string FORMAT = 'Y-m-d H:i:s.uP';

    public static function format(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format(self::FORMAT);
    }

    public static function formatNullable(?\DateTimeImmutable $at): ?string
    {
        return $at === null ? null : self::format($at);
    }

    public static function parse(string $value): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function parseNullable(?string $value): ?\DateTimeImmutable
    {
        return $value === null ? null : self::parse($value);
    }
}
