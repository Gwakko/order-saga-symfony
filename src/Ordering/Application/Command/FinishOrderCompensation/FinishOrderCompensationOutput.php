<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\FinishOrderCompensation;

use App\Ordering\Domain\OrderStatus;

final readonly class FinishOrderCompensationOutput
{
    public function __construct(public OrderStatus $status) {}
}
