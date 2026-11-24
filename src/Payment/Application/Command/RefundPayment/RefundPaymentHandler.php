<?php

declare(strict_types=1);

namespace App\Payment\Application\Command\RefundPayment;

use App\Payment\Application\Port\PaymentRepository;
use App\Payment\Application\Service\PaymentRefunder;
use App\Payment\Application\Service\RefundOutcome;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Identifier\OrderId;

/**
 * Compensation of `ChargePayment`. No payment, or one that never captured money, means nothing to refund.
 */
final readonly class RefundPaymentHandler implements CommandHandler
{
    public function __construct(private PaymentRepository $payments, private PaymentRefunder $refunder) {}

    public function __invoke(RefundPaymentCommand $command): RefundPaymentOutput
    {
        $payment = $this->payments->findByOrder(OrderId::fromString($command->orderId));

        return new RefundPaymentOutput($payment === null ? RefundOutcome::NothingToRefund : $this->refunder->refund($payment));
    }
}
