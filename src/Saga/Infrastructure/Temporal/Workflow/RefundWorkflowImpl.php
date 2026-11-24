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
use Temporal\Workflow;

/**
 * `RevokeSoldKey` -> `RefundPayment` -> `FinishOrderRefund` (EC-59). Every step is idempotent, so the activity retries
 * and a restarted worker are safe. A step that cannot be completed within its deadline parks the order for an operator
 * (`ManualReview`): the workflow does not retry forever and does not fail with an exception (EC-47).
 */
final class RefundWorkflowImpl implements RefundWorkflow
{
    private string $progress = 'starting';

    public function refund(string $orderId): \Generator
    {
        $config = SagaConfigurationHolder::get();
        $inventory = Workflow::newActivityStub(InventoryActivities::class, $config->activityOptions(ActivityProfile::RevokeSoldKey));
        $payment = Workflow::newActivityStub(PaymentActivities::class, $config->activityOptions(ActivityProfile::RefundPayment));
        $order = Workflow::newActivityStub(OrderActivities::class, $config->activityOptions(ActivityProfile::OrderStep));

        $step = SagaStep::RevokeSoldKey;

        try {
            $this->progress = $step->value;
            yield $inventory->revokeSoldKey($orderId);

            $step = SagaStep::RefundPayment;
            $this->progress = $step->value;
            yield $payment->refundPayment($orderId);

            $step = SagaStep::FinishOrderRefund;
            $this->progress = $step->value;
            yield $order->finishRefund($orderId);

            $this->progress = 'refunded';

            return 'Refunded';
        } catch (ActivityFailure) {
            $this->progress = 'manual review';
            yield $order->markManualReview($orderId, FailureReason::CompensationFailed->value, $step->value);

            return 'ManualReview';
        }
    }

    public function progress(): string
    {
        return $this->progress;
    }
}
