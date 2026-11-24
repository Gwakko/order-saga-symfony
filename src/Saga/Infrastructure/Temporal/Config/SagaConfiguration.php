<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Config;

use Carbon\CarbonInterval;
use Temporal\Activity\ActivityOptions;
use Temporal\Client\WorkflowOptions;
use Temporal\Common\IdReusePolicy;
use Temporal\Common\RetryOptions;

/**
 * Task queue, workflow deadline and the per-activity timeout table, read from `config/packages/temporal.yaml`.
 * Built (and validated) once per container; the workflow reads it through `SagaConfigurationHolder`.
 */
final readonly class SagaConfiguration
{
    public const string WORKFLOW_ID_PREFIX = 'order-';
    public const string REFUND_WORKFLOW_ID_PREFIX = 'refund-';
    public const int DEFAULT_REFUND_WORKFLOW_EXECUTION_TIMEOUT_SECONDS = 691200;

    /**
     * @param array<string, ActivitySettings> $activities keyed by `ActivityProfile` value
     */
    public function __construct(
        public string $taskQueue,
        public int $workflowExecutionTimeoutSeconds,
        public array $activities,
        public int $refundWorkflowExecutionTimeoutSeconds = self::DEFAULT_REFUND_WORKFLOW_EXECUTION_TIMEOUT_SECONDS,
    ) {
        if ($taskQueue === '' || $workflowExecutionTimeoutSeconds <= 0 || $refundWorkflowExecutionTimeoutSeconds <= 0) {
            throw new InvalidSagaConfiguration('Task queue must be set and the workflow execution timeout positive.');
        }

        foreach (ActivityProfile::cases() as $profile) {
            $settings = $activities[$profile->value] ?? throw new InvalidSagaConfiguration(sprintf('Activity profile "%s" is missing.', $profile->value));
            $this->assertProfile($profile, $settings);
        }
    }

    /**
     * Builds the configuration from the container parameters and checks the TTL invariant:
     * `reservation_ttl > ReserveKey.ScheduleToClose + ChargePayment.ScheduleToClose + ConsumeReservation.ScheduleToClose + charge_guard`.
     *
     * @param array<array-key, mixed> $activities raw `temporal.activities` parameter
     */
    public static function fromParameters(
        string $taskQueue,
        int $workflowExecutionTimeoutSeconds,
        array $activities,
        int $reservationTtlSeconds,
        int $chargeGuardSeconds,
        int $refundWorkflowExecutionTimeoutSeconds = self::DEFAULT_REFUND_WORKFLOW_EXECUTION_TIMEOUT_SECONDS,
    ): self {
        $parsed = [];

        foreach ($activities as $name => $row) {
            if (!is_string($name) || !is_array($row)) {
                throw new InvalidSagaConfiguration('temporal.activities must map profile names to settings.');
            }
            $parsed[$name] = self::parseRow($name, $row);
        }

        $configuration = new self($taskQueue, $workflowExecutionTimeoutSeconds, $parsed, $refundWorkflowExecutionTimeoutSeconds);
        $configuration->assertReservationOutlivesTheSteps($reservationTtlSeconds, $chargeGuardSeconds);

        return $configuration;
    }

    public function activityOptions(ActivityProfile $profile): ActivityOptions
    {
        $settings = $this->activities[$profile->value];

        $retry = RetryOptions::new()
            ->withInitialInterval(CarbonInterval::seconds($settings->initialIntervalSeconds))
            ->withBackoffCoefficient((float) $settings->backoff)
            ->withMaximumInterval(CarbonInterval::seconds($settings->maxIntervalSeconds))
            ->withMaximumAttempts(max(0, $settings->maxAttempts))
            ->withNonRetryableExceptions($settings->nonRetryable);

        return ActivityOptions::new()
            ->withTaskQueue($this->taskQueue)
            ->withStartToCloseTimeout(CarbonInterval::seconds($settings->startToCloseSeconds))
            ->withScheduleToCloseTimeout(CarbonInterval::seconds($settings->scheduleToCloseSeconds))
            ->withRetryOptions($retry);
    }

    /** `order-{orderId}`, reject duplicate (INV-16, 03_saga.md section 1). */
    public function workflowOptions(string $orderId): WorkflowOptions
    {
        return WorkflowOptions::new()
            ->withWorkflowId(self::WORKFLOW_ID_PREFIX . $orderId)
            ->withTaskQueue($this->taskQueue)
            ->withWorkflowExecutionTimeout(CarbonInterval::seconds($this->workflowExecutionTimeoutSeconds))
            ->withWorkflowIdReusePolicy(IdReusePolicy::RejectDuplicate);
    }

    /** `refund-{orderId}`, reject duplicate: one refund workflow per order (INV-16, EC-61). */
    public function refundWorkflowOptions(string $orderId): WorkflowOptions
    {
        return WorkflowOptions::new()
            ->withWorkflowId(self::REFUND_WORKFLOW_ID_PREFIX . $orderId)
            ->withTaskQueue($this->taskQueue)
            ->withWorkflowExecutionTimeout(CarbonInterval::seconds($this->refundWorkflowExecutionTimeoutSeconds))
            ->withWorkflowIdReusePolicy(IdReusePolicy::RejectDuplicate);
    }

    public function assertReservationOutlivesTheSteps(int $reservationTtlSeconds, int $chargeGuardSeconds): void
    {
        $deadlines = $this->activities[ActivityProfile::ReserveKey->value]->scheduleToCloseSeconds
            + $this->activities[ActivityProfile::ChargePayment->value]->scheduleToCloseSeconds
            + $this->activities[ActivityProfile::ConsumeReservation->value]->scheduleToCloseSeconds
            + $chargeGuardSeconds;

        if ($reservationTtlSeconds <= $deadlines) {
            throw new InvalidSagaConfiguration(sprintf(
                'Reservation TTL (%d s) must exceed the step deadlines plus the charge guard (%d s).',
                $reservationTtlSeconds,
                $deadlines,
            ));
        }
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private static function parseRow(string $name, array $row): ActivitySettings
    {
        $int = static fn(string $key): int => isset($row[$key]) && is_int($row[$key])
            ? $row[$key]
            : throw new InvalidSagaConfiguration(sprintf('temporal.activities.%s.%s must be an integer.', $name, $key));

        $nonRetryable = [];
        foreach (is_array($row['non_retryable'] ?? []) ? ($row['non_retryable'] ?? []) : [] as $class) {
            if (!is_string($class) || !is_a($class, \Throwable::class, true)) {
                throw new InvalidSagaConfiguration(sprintf('temporal.activities.%s.non_retryable must list exception classes.', $name));
            }
            $nonRetryable[] = $class;
        }

        return new ActivitySettings(
            $int('start_to_close'),
            $int('schedule_to_close'),
            $int('initial_interval'),
            $int('backoff'),
            $int('max_interval'),
            $int('max_attempts'),
            $nonRetryable,
        );
    }

    private function assertProfile(ActivityProfile $profile, ActivitySettings $s): void
    {
        $positive = $s->startToCloseSeconds > 0 && $s->scheduleToCloseSeconds > 0 && $s->initialIntervalSeconds > 0
            && $s->backoff >= 1 && $s->maxIntervalSeconds >= $s->initialIntervalSeconds && $s->maxAttempts >= 0;

        if (!$positive) {
            throw new InvalidSagaConfiguration(sprintf('Activity profile "%s" has a non-positive or inconsistent value.', $profile->value));
        }

        if ($s->startToCloseSeconds > $s->scheduleToCloseSeconds) {
            throw new InvalidSagaConfiguration(sprintf('Activity profile "%s": StartToClose must not exceed ScheduleToClose.', $profile->value));
        }
    }
}
