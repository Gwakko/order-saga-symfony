<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Worker;

use Temporal\DataConverter\EncodedValues;
use Temporal\Exception\Failure\ApplicationFailure;
use Temporal\Interceptor\ActivityInbound\ActivityInput;
use Temporal\Interceptor\ActivityInboundInterceptor;

/**
 * Wraps every activity run in the worker: the connection is checked before it (EC-85), and a transient database
 * failure drops the connection and is reported as a RETRYABLE `TransientDatabaseFailure`, so Temporal retries it on a
 * fresh connection instead of treating it as a business error. Everything else passes through untouched.
 */
final readonly class ActivityResilienceInterceptor implements ActivityInboundInterceptor
{
    public const string FAILURE_TYPE = 'TransientDatabaseFailure';

    public function __construct(private WorkerConnectionGuard $guard) {}

    public function handleActivityInbound(ActivityInput $input, callable $next): mixed
    {
        try {
            $this->guard->ensureAlive();

            return $next($input);
        } catch (\Throwable $failure) {
            if (!TransientDatabaseFailure::matches($failure)) {
                throw $failure;
            }

            $this->guard->discard();

            throw new ApplicationFailure($failure->getMessage(), self::FAILURE_TYPE, false, EncodedValues::empty(), $failure);
        }
    }
}
