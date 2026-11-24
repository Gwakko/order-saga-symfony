<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

/**
 * A domain event as the outbox stores it: JSON-ready scalars only, never a key code (INV-17).
 */
final readonly class NormalizedEvent
{
    /**
     * @param array<string, scalar|array<array-key, scalar|null>|null> $payload
     */
    public function __construct(public string $aggregateType, public string $eventType, public array $payload) {}
}
