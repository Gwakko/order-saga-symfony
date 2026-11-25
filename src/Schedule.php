<?php

declare(strict_types=1);

namespace App;

use App\Inventory\Infrastructure\Schedule\ExpireReservationsTick;
use App\Ordering\Infrastructure\Schedule\SweepStuckOrdersTick;
use App\Payment\Infrastructure\Schedule\ReconcilePaymentsTick;
use App\Shared\Infrastructure\Schedule\PurgeIdempotencyKeysTick;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Maintenance jobs (02_use_cases.md section 3). Run with `bin/console messenger:consume scheduler_default`.
 */
#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {}

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache) // ensure missed tasks are executed
            ->processOnlyLastMissedRun(true) // ensure only last missed task is run
            ->add(
                RecurringMessage::every('30 seconds', new ExpireReservationsTick(500)),
                RecurringMessage::every('5 minutes', new ReconcilePaymentsTick(300, 200)),
                RecurringMessage::every('5 minutes', new SweepStuckOrdersTick(100)),
                RecurringMessage::every('1 hour', new PurgeIdempotencyKeysTick(10_000)),
            );
    }
}
