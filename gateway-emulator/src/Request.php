<?php

declare(strict_types=1);

namespace GatewayEmulator;

final readonly class Request
{
    public function __construct(
        public string $method,
        public string $path,
        public ?string $idempotencyKey,
        public string $body,
    ) {}
}
