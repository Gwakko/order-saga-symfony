<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Ordering\Application\Port\DeliveredKeyReader;
use App\Shared\Domain\Identifier\ProductKeyId;
use App\Shared\Domain\KeyCode;

final readonly class FakeDeliveredKeyReader implements DeliveredKeyReader
{
    public function __construct(private InMemoryKeyInventory $keys) {}

    public function codeOf(ProductKeyId $keyId): ?KeyCode
    {
        return $this->keys->find($keyId)?->code;
    }
}
