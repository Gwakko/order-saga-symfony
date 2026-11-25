<?php

declare(strict_types=1);

namespace App\Ordering\Application\Exception;

/**
 * The workflow engine could not say whether a workflow is still running (not "it is gone"). The caller must not
 * assume the workflow is dead.
 */
final class WorkflowStatusUnavailable extends \RuntimeException {}
