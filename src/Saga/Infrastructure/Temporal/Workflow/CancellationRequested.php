<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Workflow;

/** Thrown by the guard between steps when the customer asked for cancellation; never leaves the workflow. */
final class CancellationRequested extends \RuntimeException {}
