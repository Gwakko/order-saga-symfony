<?php

declare(strict_types=1);

namespace GatewayEmulator;

final readonly class Response
{
    public function __construct(
        public int $status,
        public string $body,
        public string $contentType = 'application/json',
        /** Пауза перед отправкой ответа (режим delay). */
        public int $delayMs = 0,
        /** Объявить Content-Length больше фактического тела и закрыть соединение (режим drop-connection). */
        public bool $abort = false,
    ) {}

    /** @param array<string, mixed> $data */
    public static function json(int $status, array $data): self
    {
        return new self($status, json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function withDelay(int $delayMs): self
    {
        return new self($this->status, $this->body, $this->contentType, $delayMs, $this->abort);
    }
}
