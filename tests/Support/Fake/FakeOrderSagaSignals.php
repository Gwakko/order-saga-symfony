<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Ordering\Application\Port\OrderSagaSignals;
use App\Shared\Domain\Identifier\OrderId;

final class FakeOrderSagaSignals implements OrderSagaSignals
{
    /** @var list<string> */
    public array $cancellations = [];

    public ?\Throwable $failure = null;

    public function requestCancellation(OrderId $orderId): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->cancellations[] = $orderId->value;
    }
}
