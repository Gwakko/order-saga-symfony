<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Activity;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

/**
 * Order transitions. Activities that have nothing to report return the new aggregate version, or `true`: a `void`
 * method cannot be yielded by the workflow without tripping static analysis (the stub returns a promise).
 */
#[ActivityInterface]
interface OrderActivities
{
    #[ActivityMethod(name: 'MarkOrderKeyReserved')]
    public function markKeyReserved(string $orderId, string $reservationId): int;

    #[ActivityMethod(name: 'MarkOrderPaid')]
    public function markPaid(string $orderId, string $paymentId): int;

    #[ActivityMethod(name: 'CompleteOrder')]
    public function complete(string $orderId, string $keyId): int;

    /** @param string $reason a `FailureReason` value */
    #[ActivityMethod(name: 'FailOrder')]
    public function fail(string $orderId, string $reason): bool;

    /** @param string $reason a `FailureReason` value */
    #[ActivityMethod(name: 'StartOrderCompensation')]
    public function startCompensation(string $orderId, string $reason): bool;

    /** @return string the final `OrderStatus` value (Failed or Cancelled) */
    #[ActivityMethod(name: 'FinishOrderCompensation')]
    public function finishCompensation(string $orderId): string;

    /** @param string $reason a `FailureReason` value */
    #[ActivityMethod(name: 'MarkOrderManualReview')]
    public function markManualReview(string $orderId, string $reason, string $step): bool;

    /** The cancellation flag in the database (the signal may have been lost: 03_saga.md section 2). */
    #[ActivityMethod(name: 'IsCancellationRequested')]
    public function isCancellationRequested(string $orderId): bool;

    /** The flag was set before the saga did anything: `Pending` to `Cancelled`. */
    #[ActivityMethod(name: 'CancelOrderBeforeStart')]
    public function cancelBeforeStart(string $orderId): bool;

    /** End of `RefundWorkflow`: `Refunding` to `Refunded`. */
    #[ActivityMethod(name: 'FinishOrderRefund')]
    public function finishRefund(string $orderId): bool;
}
