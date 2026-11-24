<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Workflow;

use App\Inventory\Application\Exception\KeyIsRevoked;
use App\Inventory\Application\Exception\OutOfStock;
use App\Inventory\Domain\Exception\ReservationHasExpired;
use App\Payment\Application\Exception\AmountMismatch;
use App\Payment\Application\Exception\PaymentDeclined;
use App\Payment\Application\Exception\ReservationNotActive;
use App\Shared\Domain\Exception\CurrencyMismatch;
use App\Shared\Domain\FailureReason;
use Temporal\Exception\Failure\ActivityFailure;
use Temporal\Exception\Failure\ApplicationFailure;
use Temporal\Exception\Failure\TimeoutFailure;

/**
 * Turns the failure of an activity into the business `FailureReason` the saga compensates for. Pure: it only inspects
 * the failure (the activity's exception class travels as `ApplicationFailure::getType()`).
 */
final readonly class FailureClassifier
{
    /**
     * @return ?FailureReason null when the failure is not a business outcome of this step (the caller rethrows)
     */
    public function reasonFor(ActivityFailure $failure, SagaStep $step): ?FailureReason
    {
        $cause = $failure->getPrevious();
        $type = $cause instanceof ApplicationFailure ? $cause->getType() : null;

        $byType = match ($type) {
            OutOfStock::class => FailureReason::OutOfStock,
            PaymentDeclined::class => FailureReason::PaymentDeclined,
            CurrencyMismatch::class, AmountMismatch::class => FailureReason::CurrencyMismatch,
            ReservationNotActive::class, ReservationHasExpired::class => FailureReason::ReservationExpired,
            KeyIsRevoked::class => FailureReason::KeyRevoked,
            default => null,
        };

        if ($byType !== null) {
            return $byType;
        }

        // A charge that never settled before its deadline (breaker open, gateway down, outcome unknown): EC-34.
        $exhausted = $cause instanceof TimeoutFailure || $cause instanceof ApplicationFailure;

        return $step === SagaStep::ChargePayment && $exhausted ? FailureReason::PaymentUnavailable : null;
    }
}
