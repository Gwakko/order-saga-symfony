<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Activity;

use App\Payment\Application\Command\ChargePayment\ChargePaymentCommand;
use App\Payment\Application\Command\ChargePayment\ChargePaymentOutput;
use App\Payment\Application\Command\RefundPayment\RefundPaymentCommand;
use App\Payment\Application\Command\RefundPayment\RefundPaymentOutput;
use App\Shared\Infrastructure\Bus\CommandBus;

final readonly class PaymentActivitiesImpl implements PaymentActivities
{
    public function __construct(private CommandBus $commands) {}

    public function chargePayment(string $orderId): string
    {
        return $this->commands->execute(new ChargePaymentCommand($orderId), ChargePaymentOutput::class)->paymentId;
    }

    public function refundPayment(string $orderId): string
    {
        return $this->commands->execute(new RefundPaymentCommand($orderId), RefundPaymentOutput::class)->status->value;
    }
}
