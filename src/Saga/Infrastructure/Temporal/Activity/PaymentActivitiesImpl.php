<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Activity;

use App\Payment\Application\Command\ChargePayment\ChargePaymentCommand;
use App\Payment\Application\Command\ChargePayment\ChargePaymentOutput;
use App\Payment\Application\Command\RefundPayment\RefundPaymentCommand;
use App\Payment\Application\Command\RefundPayment\RefundPaymentOutput;
use App\Payment\Application\Query\PrepareChargeInspection\PrepareChargeInspectionOutput;
use App\Payment\Application\Query\PrepareChargeInspection\PrepareChargeInspectionQuery;
use App\Shared\Infrastructure\Bus\CommandBus;
use App\Shared\Infrastructure\Bus\QueryBus;

final readonly class PaymentActivitiesImpl implements PaymentActivities
{
    public function __construct(
        private CommandBus $commands,
        private QueryBus $queries,
    ) {}

    public function chargePayment(string $orderId): string
    {
        return $this->commands->execute(new ChargePaymentCommand($orderId), ChargePaymentOutput::class)->paymentId;
    }

    public function refundPayment(string $orderId): string
    {
        return $this->commands->execute(new RefundPaymentCommand($orderId), RefundPaymentOutput::class)->status->value;
    }

    public function prepareChargeInspection(string $orderId): array
    {
        return $this->queries->execute(new PrepareChargeInspectionQuery($orderId), PrepareChargeInspectionOutput::class)->toArray();
    }
}
