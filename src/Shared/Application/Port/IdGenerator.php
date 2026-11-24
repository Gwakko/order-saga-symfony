<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

interface IdGenerator
{
    /**
     * A new UUID v7 as a lower-case string.
     */
    public function next(): string;
}
