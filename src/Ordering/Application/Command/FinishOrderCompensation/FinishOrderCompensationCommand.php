<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\FinishOrderCompensation;

final readonly class FinishOrderCompensationCommand
{
    public function __construct(public string $orderId) {}
}
