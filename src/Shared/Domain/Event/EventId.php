<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

use App\Shared\Domain\Identifier\UuidIdentifier;

/**
 * Event identifiers are derived, not generated: the domain has no randomness. The id is a name-based UUID (v5, RFC 9562)
 * of "aggregate id : version : event type", so the same transition always yields the same id (inbox deduplication).
 */
final readonly class EventId extends UuidIdentifier
{
    private const NAMESPACE = '6f1d2c3e-4b5a-4768-9a0b-1c2d3e4f5a6b';

    public static function derive(string $aggregateId, int $aggregateVersion, string $eventType): self
    {
        $hash = sha1(self::NAMESPACE . ':' . $aggregateId . ':' . $aggregateVersion . ':' . $eventType);
        $time = substr($hash, 12, 4);
        $variant = dechex((hexdec($hash[16]) & 0x3) | 0x8);

        return new self(sprintf(
            '%s-%s-5%s-%s%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($time, 1, 3),
            $variant,
            substr($hash, 17, 3),
            substr($hash, 20, 12),
        ));
    }
}
