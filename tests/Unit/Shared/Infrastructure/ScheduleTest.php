<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Inventory\Infrastructure\Schedule\ExpireReservationsTick;
use App\Payment\Infrastructure\Schedule\ReconcilePaymentsTick;
use App\Schedule;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Scheduler\Generator\MessageContext;

#[Group('M4.6')]
final class ScheduleTest extends TestCase
{
    public function testBothMaintenanceJobsAreScheduled(): void
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

        self::assertSame([ExpireReservationsTick::class, ReconcilePaymentsTick::class], array_keys($messages));
        self::assertSame('2026-05-01T10:00:30+00:00', $messages[ExpireReservationsTick::class]->format('c'));
        self::assertSame('2026-05-01T10:05:00+00:00', $messages[ReconcilePaymentsTick::class]->format('c'));
    }
}
