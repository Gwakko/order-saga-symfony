<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\FailOrder;

use App\Shared\Domain\FailureReason;

final readonly class FailOrderCommand
{
    public function __construct(public string $orderId, public FailureReason $reason) {}
}
