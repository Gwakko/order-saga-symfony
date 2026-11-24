<?php

declare(strict_types=1);

namespace App\Payment\Application\Command\ChargePayment;

/**
 * Only the order id: the amount comes from the stored order (INV-18, EC-81).
 */
final readonly class ChargePaymentCommand
{
    public function __construct(public string $orderId) {}
}
