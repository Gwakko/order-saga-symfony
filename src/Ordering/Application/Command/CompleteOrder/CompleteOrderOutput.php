<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\CompleteOrder;

final readonly class CompleteOrderOutput
{
    public function __construct(public int $version) {}
}
