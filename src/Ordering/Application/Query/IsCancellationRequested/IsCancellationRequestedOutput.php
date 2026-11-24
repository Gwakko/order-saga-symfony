<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\IsCancellationRequested;

final readonly class IsCancellationRequestedOutput
{
    public function __construct(public bool $requested) {}
}
