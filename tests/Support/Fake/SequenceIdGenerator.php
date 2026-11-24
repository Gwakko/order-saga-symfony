<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Shared\Application\Port\IdGenerator;

/**
 * Deterministic ids: 0190a5c4-9000-7000-8000-000000000001, ...0002, and so on.
 */
final class SequenceIdGenerator implements IdGenerator
{
    private int $counter = 0;

    public function next(): string
    {
        ++$this->counter;

        return sprintf('0190a5c4-9000-7000-8000-%012d', $this->counter);
    }

    public function peek(int $n): string
    {
        return sprintf('0190a5c4-9000-7000-8000-%012d', $n);
    }
}
