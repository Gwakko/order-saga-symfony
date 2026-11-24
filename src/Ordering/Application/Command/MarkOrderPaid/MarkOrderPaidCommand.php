<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\MarkOrderPaid;

final readonly class MarkOrderPaidCommand
{
    public function __construct(public string $orderId, public string $paymentId) {}
}
