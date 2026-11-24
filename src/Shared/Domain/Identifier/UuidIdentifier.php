<?php

declare(strict_types=1);

namespace App\Shared\Domain\Identifier;

use App\Shared\Domain\Exception\InvalidIdentifier;

/**
 * Value object over a lower-case UUID string (RFC 4122 / RFC 9562, versions 1-8). Generation is not the domain's job.
 */
abstract readonly class UuidIdentifier
{
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    final public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidIdentifier(static::class, $value);
        }
    }

    final public static function fromString(string $value): static
    {
        return new static($value);
    }

    final public function equals(self $other): bool
    {
        return $this::class === $other::class && $this->value === $other->value;
    }
}
