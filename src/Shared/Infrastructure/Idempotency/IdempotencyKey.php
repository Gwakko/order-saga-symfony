<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Idempotency;

use App\Shared\Infrastructure\Idempotency\Exception\IdempotencyKeyInvalid;
use App\Shared\Infrastructure\Idempotency\Exception\IdempotencyKeyMissing;

/**
 * The client-chosen key: 1..255 characters of `[A-Za-z0-9_-]` (EC-04).
 */
final readonly class IdempotencyKey
{
    public const int MAX_LENGTH = 255;

    private function __construct(public string $value) {}

    public static function fromHeader(?string $header): self
    {
        if ($header === null) {
            throw new IdempotencyKeyMissing();
        }

        if ($header === '' || strlen($header) > self::MAX_LENGTH || preg_match('/^[A-Za-z0-9_-]+$/D', $header) !== 1) {
            throw new IdempotencyKeyInvalid();
        }

        return new self($header);
    }
}
