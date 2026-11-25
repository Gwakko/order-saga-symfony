<?php

declare(strict_types=1);

namespace App\Payment\Application\Query\PrepareChargeInspection;

use App\Payment\Application\Port\PaymentRepository;
use App\Payment\Domain\PaymentStatus;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Domain\Identifier\OrderId;

/**
 * Read by the saga after `ChargePayment` gave up (docs/spec/09_go_worker.md section 2.3). The gateway is asked only when the
 * switch is on and a payment exists whose outcome is still open (`Pending`, `Unknown`); any other status is already
 * decided and the saga keeps its old behaviour. The switch lives here, not in the workflow, so it can change without
 * touching workflow code and a workflow test decides it by mocking this activity.
 */
final readonly class PrepareChargeInspectionHandler implements QueryHandler
{
    public function __construct(
        private PaymentRepository $payments,
        private bool $enabled = false,
    ) {}

    public function __invoke(PrepareChargeInspectionQuery $query): PrepareChargeInspectionOutput
    {
        if (!$this->enabled) {
            return PrepareChargeInspectionOutput::skip();
        }

        $payment = $this->payments->findByOrder(OrderId::fromString($query->orderId));

        if ($payment === null || !in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Unknown], true)) {
            return PrepareChargeInspectionOutput::skip();
        }

        return PrepareChargeInspectionOutput::inspect($payment->id->value, $payment->amount->amount, $payment->amount->currency);
    }
}
