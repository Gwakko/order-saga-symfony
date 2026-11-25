<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use App\Saga\Infrastructure\Temporal\Config\InvalidSagaConfiguration;
use App\Saga\Infrastructure\Temporal\Config\SagaConfiguration;

/**
 * `SagaConfiguration` of the `test` environment with one optional change for the end-to-end run of the Go worker
 * (docs/spec/09_go_worker.md Q-M9-3, M9.5): the ScheduleToClose of the `charge_payment` profile can be shortened with
 * `TEMPORAL_E2E_CHARGE_SCHEDULE_TO_CLOSE` (seconds, 0 = not set). With the real 300 s every run of `make test-go-e2e` would
 * wait five minutes for `ChargePayment` to give up. Only a shorter value is accepted, so the TTL invariant (T-CFG-01: the
 * sum of the ScheduleToClose values stays below the reservation TTL) is still true, and `fromParameters` checks it again
 * for the shortened table. `config/packages/temporal.yaml` and production code are not touched; the dev, prod and
 * unit-test configurations never see the variable.
 */
final class E2eSagaConfiguration
{
    /**
     * @param array<array-key, mixed> $activities raw `temporal.activities` parameter
     */
    public static function create(
        string $taskQueue,
        int $workflowExecutionTimeoutSeconds,
        array $activities,
        int $reservationTtlSeconds,
        int $chargeGuardSeconds,
        int $refundWorkflowExecutionTimeoutSeconds,
        int $chargeScheduleToCloseOverride,
    ): SagaConfiguration {
        if ($chargeScheduleToCloseOverride !== 0) {
            $row = $activities['charge_payment'] ?? null;

            if (!is_array($row) || !is_int($row['schedule_to_close'] ?? null)) {
                throw new InvalidSagaConfiguration('Profile charge_payment has no integer schedule_to_close to shorten.');
            }

            if ($chargeScheduleToCloseOverride < 1 || $chargeScheduleToCloseOverride >= $row['schedule_to_close']) {
                throw new InvalidSagaConfiguration(sprintf(
                    'TEMPORAL_E2E_CHARGE_SCHEDULE_TO_CLOSE must be shorter than the configured %d s (and positive), got %d.',
                    $row['schedule_to_close'],
                    $chargeScheduleToCloseOverride,
                ));
            }

            $row['schedule_to_close'] = $chargeScheduleToCloseOverride;
            $activities['charge_payment'] = $row;
        }

        return SagaConfiguration::fromParameters(
            $taskQueue,
            $workflowExecutionTimeoutSeconds,
            $activities,
            $reservationTtlSeconds,
            $chargeGuardSeconds,
            $refundWorkflowExecutionTimeoutSeconds,
        );
    }
}
