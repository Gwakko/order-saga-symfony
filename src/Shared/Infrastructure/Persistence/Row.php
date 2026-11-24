<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence;

/**
 * Typed access to a DBAL result row (`array<string, mixed>`): one place that narrows the driver's `mixed`.
 */
final readonly class Row
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private array $values) {}

    public function string(string $column): string
    {
        $value = $this->values[$column] ?? null;

        return is_string($value) ? $value : throw new \UnexpectedValueException(sprintf('Column "%s" is not a string.', $column));
    }

    public function nullableString(string $column): ?string
    {
        $value = $this->values[$column] ?? null;

        return $value === null ? null : $this->string($column);
    }

    public function int(string $column): int
    {
        $value = $this->values[$column] ?? null;

        return match (true) {
            is_int($value) => $value,
            is_string($value) && ctype_digit($value) => (int) $value,
            default => throw new \UnexpectedValueException(sprintf('Column "%s" is not an integer.', $column)),
        };
    }

    public function bool(string $column): bool
    {
        $value = $this->values[$column] ?? null;

        return match (true) {
            is_bool($value) => $value,
            $value === 't', $value === '1', $value === 1 => true,
            $value === 'f', $value === '0', $value === 0 => false,
            default => throw new \UnexpectedValueException(sprintf('Column "%s" is not a boolean.', $column)),
        };
    }

    public function time(string $column): \DateTimeImmutable
    {
        return DbTimestamp::parse($this->string($column));
    }

    public function nullableTime(string $column): ?\DateTimeImmutable
    {
        return DbTimestamp::parseNullable($this->nullableString($column));
    }
}
