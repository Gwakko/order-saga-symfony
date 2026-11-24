<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\RevokeSoldKey;

final readonly class RevokeSoldKeyOutput
{
    public function __construct(public string $keyId) {}
}
