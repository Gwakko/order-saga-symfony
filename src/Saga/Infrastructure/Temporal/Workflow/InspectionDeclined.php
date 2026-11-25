<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Workflow;

/**
 * The gateway proved the charge was declined (docs/spec/09_go_worker.md section 2.3): the saga compensates with
 * `PaymentDeclined` instead of `PaymentUnavailable`. Thrown and caught inside the workflow only.
 */
final class InspectionDeclined extends \RuntimeException {}
