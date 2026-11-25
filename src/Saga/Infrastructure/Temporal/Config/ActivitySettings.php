<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Config;

/**
 * Timeouts and retry policy of one activity profile. All durations are whole seconds here; the conversion to
 * the SDK's `DateInterval` happens in `SagaConfiguration`, because a bare integer means milliseconds in the SDK.
 */
final readonly class ActivitySettings
{
    /**
     * @param int                           $maxAttempts  0 = unlimited within ScheduleToClose
     * @param int                           $backoff      retry backoff coefficient (a whole number, 2 = doubling)
     * @param list<class-string<\Throwable>> $nonRetryable exception classes that end the retries at once
     * @param ?string                       $taskQueue    queue of the activity; null = the saga queue (the Go worker has its own)
     * @param ?int                          $scheduleToStartSeconds null = not set
     * @param list<string>                  $nonRetryableTypes failure type NAMES (not classes) raised by another language's worker
     */
    public function __construct(
        public int $startToCloseSeconds,
        public int $scheduleToCloseSeconds,
        public int $initialIntervalSeconds,
        public int $backoff,
        public int $maxIntervalSeconds,
        public int $maxAttempts,
        public array $nonRetryable,
        public ?string $taskQueue = null,
        public ?int $scheduleToStartSeconds = null,
        public array $nonRetryableTypes = [],
    ) {}
}
