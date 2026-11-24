<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Idempotency;

final readonly class IdempotencyDecision
{
    private function __construct(
        public IdempotencyOutcome $outcome,
        public ?int $responseStatus = null,
        public ?string $responseBody = null,
    ) {}

    public static function started(): self
    {
        return new self(IdempotencyOutcome::Started);
    }

    public static function replay(int $status, string $body): self
    {
        return new self(IdempotencyOutcome::Replay, $status, $body);
    }

    public static function inProgress(): self
    {
        return new self(IdempotencyOutcome::InProgress);
    }

    public static function keyReused(): self
    {
        return new self(IdempotencyOutcome::KeyReused);
    }
}
