<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Shared\Domain\Identifier\ProductKeyId;
use App\Shared\Domain\KeyCode;

/**
 * Read-only view on the sold key that `CompleteOrder` needs for the masked code in `OrderCompleted`.
 * Declared by the consumer (Ordering), implemented over Inventory data in infrastructure.
 */
interface DeliveredKeyReader
{
    public function codeOf(ProductKeyId $keyId): ?KeyCode;
}
