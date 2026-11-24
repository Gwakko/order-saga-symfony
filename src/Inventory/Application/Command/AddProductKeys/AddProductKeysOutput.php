<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\AddProductKeys;

final readonly class AddProductKeysOutput
{
    public function __construct(public int $added, public int $duplicates) {}
}
