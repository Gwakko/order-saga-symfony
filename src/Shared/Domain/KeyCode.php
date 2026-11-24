<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use App\Shared\Domain\Exception\InvalidKeyCode;

/**
 * Secret product key. There is deliberately no __toString and no public property: use masked() for logs and events
 * (INV-17) and reveal() only where the customer must receive the key.
 */
final readonly class KeyCode
{
    private const MIN_LENGTH = 5;
    private const MAX_LENGTH = 64;
    private const VISIBLE_SUFFIX = 4;

    private string $value;

    public function __construct(string $value)
    {
        if (strlen($value) < self::MIN_LENGTH || strlen($value) > self::MAX_LENGTH || preg_match('/^[A-Z0-9-]+$/D', $value) !== 1) {
            throw new InvalidKeyCode();
        }

        $this->value = $value;
    }

    public function masked(): string
    {
        return 'XXXX-...-' . substr($this->value, -self::VISIBLE_SUFFIX);
    }

    public function reveal(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }
}
