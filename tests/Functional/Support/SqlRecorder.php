<?php

declare(strict_types=1);

namespace App\Tests\Functional\Support;

use Psr\Log\AbstractLogger;

/**
 * Collects every SQL statement the DBAL logging middleware reports, so a test can count the reads a request makes
 * (T-F-32). Registered as the middleware's logger in `services_test.yaml`.
 */
final class SqlRecorder extends AbstractLogger
{
    /** @var list<string> */
    private array $statements = [];

    /**
     * @param array<array-key, mixed> $context
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $sql = $context['sql'] ?? null;

        if (is_string($sql)) {
            $this->statements[] = $sql;
        }
    }

    public function reset(): void
    {
        $this->statements = [];
    }

    /**
     * @return list<string>
     */
    public function statements(): array
    {
        return $this->statements;
    }

    /**
     * Statements whose text contains `$fragment` (for example a table name).
     */
    public function countContaining(string $fragment): int
    {
        return count(array_filter($this->statements, static fn(string $sql): bool => str_contains($sql, $fragment)));
    }
}
