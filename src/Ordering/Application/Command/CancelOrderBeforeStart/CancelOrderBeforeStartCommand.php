<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\CancelOrderBeforeStart;

final readonly class CancelOrderBeforeStartCommand
{
    public function __construct(public string $orderId) {}
}
