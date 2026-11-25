<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Workflow;

use App\Saga\Infrastructure\Temporal\Activity\GatewayInspectionActivities;
use App\Saga\Infrastructure\Temporal\Activity\InventoryActivities;
use App\Saga\Infrastructure\Temporal\Activity\OrderActivities;
use App\Saga\Infrastructure\Temporal\Activity\PaymentActivities;
use App\Saga\Infrastructure\Temporal\Config\ActivityProfile;
use App\Saga\Infrastructure\Temporal\Config\SagaConfigurationHolder;
use App\Saga\Infrastructure\Temporal\Contract\GatewayInspection\InspectionContractViolation;
use App\Saga\Infrastructure\Temporal\Contract\GatewayInspection\InspectionOutcome;
use App\Saga\Infrastructure\Temporal\Contract\GatewayInspection\InspectionRequest;
use App\Saga\Infrastructure\Temporal\Contract\GatewayInspection\InspectionResult;
use App\Shared\Domain\FailureReason;
use Temporal\Exception\Failure\ActivityFailure;
use Temporal\Exception\Failure\ApplicationFailure;
use Temporal\Exception\Failure\TimeoutFailure;
use Temporal\Internal\Workflow\ActivityProxy;
use Temporal\Workflow;
use Temporal\Workflow\Saga;

/**
 * Deterministic orchestration only: no database, no clock, no randomness (INV-18, T-ARCH-09). Every effect is an
 * activity that calls a use case. Cancellation: the `requestCancellation` signal sets a flag, and a guard (the flag or the
 * flag in the database, 03_saga.md sections 2 and 5) runs before the first step, before the charge and before consuming
 * the reservation; consuming is the point of no return, after it a cancellation is ignored (EC-41).
 *
 * Gateway inspection (M9, docs/spec/09_go_worker.md section 2.3): when `ChargePayment` gave up with an unknown outcome
 * (EC-34), the saga may ask the gateway what happened to the charge, through an activity served by the Go worker. The branch
 * sits behind `Workflow::getVersion`, so histories recorded before it replay unchanged, and behind a switch that the PHP
 * activity `PrepareChargeInspection` reads (off by default). Any problem with the inspection leaves the pre-M9 behavior.
 */
final class OrderFulfillmentWorkflowImpl implements OrderFulfillmentWorkflow
{
    /** Change id of the gateway-inspection branch; renaming it breaks the replay of every history that holds the marker. */
    public const string GATEWAY_INSPECTION_CHANGE = 'gateway-inspection';

    /** Types of Go failures that mean a bug on one of the sides (non-retryable, errors.json): logged as errors. */
    private const array INSPECTION_BUG_TYPES = ['InvalidInspectionInput', 'UnsupportedContractVersion'];

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
        $recovery = Workflow::newActivityStub(PaymentActivities::class, $config->activityOptions(ActivityProfile::ChargeRecovery));
        $preparing = Workflow::newActivityStub(PaymentActivities::class, $config->activityOptions(ActivityProfile::OrderStep));
        $inspector = Workflow::newActivityStub(GatewayInspectionActivities::class, $config->activityOptions(ActivityProfile::InspectGatewayCharge));

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

            try {
                $paymentId = self::text(yield $payment->chargePayment($orderId));
            } catch (ActivityFailure $failure) {
                // Rethrows the failure itself unless the gateway confirmed a successful charge.
                $paymentId = yield from $this->inspectExhaustedCharge($failure, $orderId, $preparing, $inspector, $recovery);
            }

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
        } catch (InspectionDeclined) {
            // The gateway proved the decline; `Unknown` payment is closed by ReconcilePayments (EC-100).
            return yield from $this->compensate($saga, $order, $orderId, FailureReason::PaymentDeclined, $step);
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
     * `ChargePayment` failed. Only an exhausted charge with an unknown outcome (EC-34) is looked into, and only for runs
     * that took the `gateway-inspection` version (histories from before M9 never reach the inspection). Returns the id of
     * the payment once the gateway confirmed the charge and the repeated `ChargePayment` recorded it; throws the original
     * failure in every other case (the pre-M9 behavior), or `InspectionDeclined` when the gateway proved a decline.
     *
     * @param ActivityProxy<PaymentActivities>            $preparing
     * @param ActivityProxy<GatewayInspectionActivities>  $inspector
     * @param ActivityProxy<PaymentActivities>            $recovery
     *
     * @return \Generator<int, mixed, mixed, string> the payment id
     *
     * @throws InspectionDeclined
     */
    private function inspectExhaustedCharge(ActivityFailure $failure, string $orderId, object $preparing, object $inspector, object $recovery): \Generator
    {
        if ((new FailureClassifier())->reasonFor($failure, SagaStep::ChargePayment) !== FailureReason::PaymentUnavailable) {
            throw $failure;
        }

        $version = yield Workflow::getVersion(self::GATEWAY_INSPECTION_CHANGE, Workflow::DEFAULT_VERSION, 1);

        if ($version === Workflow::DEFAULT_VERSION) {
            throw $failure;
        }

        $this->progress = 'inspecting payment';

        try {
            $prepared = yield $preparing->prepareChargeInspection($orderId);
        } catch (ActivityFailure) {
            throw $failure; // no data to ask with
        }

        if (!is_array($prepared) || ($prepared['inspect'] ?? false) !== true) {
            throw $failure; // the switch is off, there is no payment, or its status is already decided
        }

        try {
            $request = InspectionRequest::from($prepared, $orderId);
            $answer = yield $inspector->inspectGatewayCharge($request->toArray());
            $result = InspectionResult::fromArray(is_array($answer) ? $answer : throw InspectionContractViolation::because('the result is not an object'));
        } catch (ActivityFailure $inspection) {
            $this->logInspectionFailure($inspection);

            throw $failure;
        } catch (InspectionContractViolation $violation) {
            Workflow::getLogger()->error('InspectGatewayCharge contract violation, keeping the pre-M9 behavior', ['reason' => $violation->getMessage()]);

            throw $failure;
        }

        if ($result->outcome === InspectionOutcome::Declined) {
            throw new InspectionDeclined();
        }

        if ($result->outcome === InspectionOutcome::Succeeded && $result->matchesExpected) {
            // Same idempotency key as the first attempt: the gateway does not charge twice, the use case records the success.
            $this->progress = 'recovering payment';

            return self::text(yield $recovery->chargePayment($orderId));
        }

        throw $failure; // not found, or a charge of another amount or currency: RefundPayment sorts it out
    }

    private function logInspectionFailure(ActivityFailure $inspection): void
    {
        $cause = $inspection->getPrevious();

        if ($cause instanceof TimeoutFailure) {
            Workflow::getLogger()->warning('InspectGatewayCharge timed out, keeping the pre-M9 behavior', ['timeoutType' => $cause->getTimeoutType()]);

            return;
        }

        $type = $cause instanceof ApplicationFailure ? $cause->getType() : 'unknown';
        $context = ['failureType' => $type];

        if (in_array($type, self::INSPECTION_BUG_TYPES, true)) {
            Workflow::getLogger()->error('InspectGatewayCharge rejected the request, keeping the pre-M9 behavior', $context);

            return;
        }

        Workflow::getLogger()->warning('InspectGatewayCharge failed, keeping the pre-M9 behavior', $context);
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
