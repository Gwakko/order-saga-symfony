<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\RevokeKey;

final readonly class RevokeKeyCommand
{
    public function __construct(public string $keyId) {}
}
