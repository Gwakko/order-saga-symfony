<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\AddProductKeys;

use App\Shared\Application\Exception\InvalidInput;

final readonly class AddProductKeysCommand
{
    /**
     * @param list<string> $codes
     */
    public function __construct(public string $productId, public array $codes)
    {
        if ($codes === []) {
            throw new InvalidInput('codes', 'must not be empty');
        }
    }
}
