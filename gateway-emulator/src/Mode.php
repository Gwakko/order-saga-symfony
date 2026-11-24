<?php

declare(strict_types=1);

namespace GatewayEmulator;

final readonly class Mode
{
    public function __construct(
        public ModeKind $kind,
        public int $parameter = 0,
    ) {}

    public static function ok(): self
    {
        return new self(ModeKind::Ok);
    }

    /** Разбирает строку вида `ok`, `delay:12000`, `http503:2`. Возвращает null для неизвестного режима. */
    public static function parse(string $raw): ?self
    {
        $parts = explode(':', $raw, 2);
        $kind = ModeKind::tryFrom($parts[0]);

        if ($kind === null) {
            return null;
        }

        if (!$kind->takesParameter()) {
            return count($parts) === 1 ? new self($kind) : null;
        }

        if (count($parts) !== 2 || !ctype_digit($parts[1])) {
            return null;
        }

        return new self($kind, (int) $parts[1]);
    }

    public function toString(): string
    {
        return $this->kind->takesParameter() ? $this->kind->value . ':' . $this->parameter : $this->kind->value;
    }
}
