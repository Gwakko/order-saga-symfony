<?php

declare(strict_types=1);

namespace App\Shared\Application\Exception;

/**
 * Marks an infrastructure-level failure that a saga activity may retry (02_use_cases.md section 6).
 * Every error without this marker is a business outcome and must not be retried.
 */
interface RetryableFailure extends \Throwable {}
