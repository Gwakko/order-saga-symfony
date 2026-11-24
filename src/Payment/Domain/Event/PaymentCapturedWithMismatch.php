<?php

declare(strict_types=1);

namespace App\Payment\Domain\Event;

use App\Payment\Domain\MismatchReason;
use App\Shared\Domain\Event\EventId;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\PaymentId;
use App\Shared\Domain\Money;

/**
 * The gateway captured money in another currency or amount than requested. `amount` is the requested amount
 * (INV-18), `reason->charged` the captured one; reconciliation compares them.
 */
final readonly class PaymentCapturedWithMismatch extends PaymentEvent
{
    public function __construct(
        EventId $id,
        PaymentId $paymentId,
        OrderId $orderId,
        Money $amount,
        int $version,
        \DateTimeImmutable $occurredOn,
        public string $chargeId,
        public MismatchReason $reason,
    ) {
        parent::__construct($id, $paymentId, $orderId, $amount, $version, $occurredOn);
    }
}
