<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Inventory\Infrastructure\Schedule\ExpireReservationsTick;
use App\Ordering\Infrastructure\Schedule\SweepStuckOrdersTick;
use App\Payment\Infrastructure\Schedule\ReconcilePaymentsTick;
use App\Shared\Infrastructure\Schedule\PurgeIdempotencyKeysTick;
use App\Schedule;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Scheduler\Generator\MessageContext;

#[Group('M4.6')]
#[Group('M8a')]
final class ScheduleTest extends TestCase
{
    public function testAllMaintenanceJobsAreScheduled(): void
    {
        $messages = [];
        $now = new \DateTimeImmutable('2026-05-01T10:00:00Z');

        foreach ((new Schedule(new ArrayAdapter()))->getSchedule()->getRecurringMessages() as $recurring) {
            $run = $recurring->getTrigger()->getNextRunDate($now);
            self::assertNotNull($run);

            foreach ($recurring->getMessages(new MessageContext('default', $recurring->getId(), $recurring->getTrigger(), $now)) as $message) {
                $messages[$message::class] = $run;
            }
        }

        // M8a added the stuck-order sweeper and the idempotency purge to the two jobs of M4.6.
        self::assertSame([ExpireReservationsTick::class, ReconcilePaymentsTick::class, SweepStuckOrdersTick::class, PurgeIdempotencyKeysTick::class], array_keys($messages));
        self::assertSame('2026-05-01T10:00:30+00:00', $messages[ExpireReservationsTick::class]->format('c'));
        self::assertSame('2026-05-01T10:05:00+00:00', $messages[ReconcilePaymentsTick::class]->format('c'));
        self::assertSame('2026-05-01T10:05:00+00:00', $messages[SweepStuckOrdersTick::class]->format('c'));
        self::assertSame('2026-05-01T11:00:00+00:00', $messages[PurgeIdempotencyKeysTick::class]->format('c'));
    }
}
