<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\ReserveKey;

final readonly class ReserveKeyCommand
{
    public function __construct(public string $orderId, public string $productId) {}
}
