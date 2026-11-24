<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\IsCancellationRequested;

final readonly class IsCancellationRequestedQuery
{
    public function __construct(public string $orderId) {}
}
