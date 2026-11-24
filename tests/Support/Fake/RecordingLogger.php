<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use Psr\Log\AbstractLogger;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => is_string($level) ? $level : get_debug_type($level), 'message' => (string) $message];
    }

    public function hasRecord(string $level): bool
    {
        foreach ($this->records as $record) {
            if ($record['level'] === $level) {
                return true;
            }
        }

        return false;
    }
}
