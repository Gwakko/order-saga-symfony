<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Worker;

use Symfony\Component\DependencyInjection\ServicesResetter;

/**
 * The activity finalizer of the worker: no state leaks from one activity to the next (open transaction, buffered logs,
 * stateful services tagged `kernel.reset`).
 */
final readonly class ActivityLifecycle
{
    public function __construct(private WorkerConnectionGuard $guard, private ServicesResetter $resetter) {}

    public function finish(): void
    {
        try {
            $this->guard->settle();
        } finally {
            $this->resetter->reset();
        }
    }
}
