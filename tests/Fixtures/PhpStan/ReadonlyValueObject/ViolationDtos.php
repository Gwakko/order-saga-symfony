<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\AddKeys;

final class AddKeysCommand
{
    public function __construct(public readonly int $count) {}
}

final readonly class AddKeysHandler {}

class AddKeysOutput {}
