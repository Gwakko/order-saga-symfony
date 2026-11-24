<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\StartOrderCompensation;

use App\Shared\Domain\FailureReason;

final readonly class StartOrderCompensationCommand
{
    public function __construct(public string $orderId, public FailureReason $reason) {}
}
