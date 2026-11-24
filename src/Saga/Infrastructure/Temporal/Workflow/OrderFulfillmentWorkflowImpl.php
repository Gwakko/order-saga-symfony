<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Workflow;

use App\Saga\Infrastructure\Temporal\Activity\InventoryActivities;
use App\Saga\Infrastructure\Temporal\Activity\OrderActivities;
use App\Saga\Infrastructure\Temporal\Activity\PaymentActivities;
use App\Saga\Infrastructure\Temporal\Config\ActivityProfile;
use App\Saga\Infrastructure\Temporal\Config\SagaConfigurationHolder;
use App\Shared\Domain\FailureReason;
use Temporal\Exception\Failure\ActivityFailure;
use Temporal\Internal\Workflow\ActivityProxy;
use Temporal\Workflow;
use Temporal\Workflow\Saga;

/**
 * Deterministic orchestration only: no database, no clock, no randomness (INV-18, T-ARCH-09). Every effect is an
 * activity that calls a use case. Cancellation: the `requestCancellation` signal sets a flag, and a guard (the flag or the
 * flag in the database, 03_saga.md sections 2 and 5) runs before the first step, before the charge and before consuming
 * the reservation; consuming is the point of no return, after it a cancellation is ignored (EC-41).
 */
final class OrderFulfillmentWorkflowImpl implements OrderFulfillmentWorkflow
{
    private string $progress = 'starting';
    private bool $cancelRequested = false;

    public function fulfil(string $orderId): \Generator
    {
        $config = SagaConfigurationHolder::get();
        $inventory = Workflow::newActivityStub(InventoryActivities::class, $config->activityOptions(ActivityProfile::ReserveKey));
        $consuming = Workflow::newActivityStub(InventoryActivities::class, $config->activityOptions(ActivityProfile::ConsumeReservation));
        $releasing = Workflow::newActivityStub(InventoryActivities::class, $config->activityOptions(ActivityProfile::ReleaseReservation));
        $payment = Workflow::newActivityStub(PaymentActivities::class, $config->activityOptions(ActivityProfile::ChargePayment));
        $refunding = Workflow::newActivityStub(PaymentActivities::class, $config->activityOptions(ActivityProfile::RefundPayment));
        $order = Workflow::newActivityStub(OrderActivities::class, $config->activityOptions(ActivityProfile::OrderStep));

        $saga = new Saga();
        $saga->setParallelCompensation(false);
        $step = SagaStep::ReserveKey;

        try {
            yield from $this->guardCancellation($order, $orderId);

            $this->progress = $step->value;
            $reservationId = self::text(yield $inventory->reserveKey($orderId));
            $saga->addCompensation(static function () use ($releasing, $orderId): \Generator {
                yield $releasing->releaseReservation($orderId);
            });

            $step = SagaStep::MarkKeyReserved;
            $this->progress = $step->value;
            yield $order->markKeyReserved($orderId, $reservationId);

            yield from $this->guardCancellation($order, $orderId);

            // Registered BEFORE the charge: its outcome may be unknown, and RefundPayment resolves that (03_saga.md section 2).
            $saga->addCompensation(static function () use ($refunding, $orderId): \Generator {
                yield $refunding->refundPayment($orderId);
            });

            $step = SagaStep::ChargePayment;
            $this->progress = $step->value;
            $paymentId = self::text(yield $payment->chargePayment($orderId));

            $step = SagaStep::MarkPaid;
            $this->progress = $step->value;
            yield $order->markPaid($orderId, $paymentId);

            yield from $this->guardCancellation($order, $orderId);

            $step = SagaStep::ConsumeReservation;
            $this->progress = $step->value;
            $keyId = self::text(yield $consuming->consumeReservation($orderId));

            // Point of no return: from here the key is sold.
            $step = SagaStep::CompleteOrder;
            $this->progress = $step->value;
            yield $order->complete($orderId, $keyId);

            $this->progress = 'completed';

            return 'Completed';
        } catch (CancellationRequested) {
            // The guard before the first step: nothing was done, so there is nothing to compensate (EC-38).
            if ($step === SagaStep::ReserveKey) {
                $this->progress = 'cancelled';
                yield $order->cancelBeforeStart($orderId);

                return 'Cancelled';
            }

            return yield from $this->compensate($saga, $order, $orderId, FailureReason::CancelledByCustomer, $step);
        } catch (ActivityFailure $failure) {
            $reason = (new FailureClassifier())->reasonFor($failure, $step);

            // Not a business outcome (infrastructure kept failing until ScheduleToClose): the effects of the step are
            // unknown, so nothing is compensated blindly and the order is parked for an operator (M6.4).
            if ($reason === null) {
                $this->progress = 'manual review';
                yield $order->markManualReview($orderId, FailureReason::SagaInterrupted->value, $step->value);

                return 'ManualReview';
            }

            // Nothing was done yet: the order simply fails (EC-14, EC-48).
            if ($step === SagaStep::ReserveKey) {
                $this->progress = 'failed';
                yield $order->fail($orderId, $reason->value);

                return 'Failed';
            }

            return yield from $this->compensate($saga, $order, $orderId, $reason, $step);
        }
    }

    public function requestCancellation(): void
    {
        $this->cancelRequested = true;
    }

    /**
     * The signal is the fast path; the flag in the database covers a signal that never arrived (EC-44, EC-38).
     *
     * @param ActivityProxy<OrderActivities> $order
     *
     * @return \Generator<int, mixed, mixed, void>
     */
    private function guardCancellation(object $order, string $orderId): \Generator
    {
        $requested = $this->cancelRequested || (yield $order->isCancellationRequested($orderId)) === true;

        if ($requested) {
            throw new CancellationRequested();
        }
    }

    /**
     * Compensations run last-registered first (RefundPayment, then ReleaseReservation), one after the other. A
     * compensation that cannot finish parks the order for an operator instead of failing the workflow (EC-47).
     *
     * @param ActivityProxy<OrderActivities> $order
     *
     * @return \Generator<int, mixed, mixed, string> the final `OrderStatus` value
     */
    private function compensate(Saga $saga, object $order, string $orderId, FailureReason $reason, SagaStep $step): \Generator
    {
        $this->progress = 'compensating';
        yield $order->startCompensation($orderId, $reason->value);

        try {
            yield $saga->compensate();
        } catch (\Throwable) {
            $this->progress = 'manual review';
            yield $order->markManualReview($orderId, FailureReason::CompensationFailed->value, $step->value);

            return 'ManualReview';
        }

        $final = self::text(yield $order->finishCompensation($orderId));
        $this->progress = 'compensated';

        return $final;
    }

    public function progress(): string
    {
        return $this->progress;
    }

    /** Activity results arrive untyped through `yield`; the interfaces promise strings. */
    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : throw new \UnexpectedValueException('An activity returned a non-string result.');
    }
}
