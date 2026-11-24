<?php

declare(strict_types=1);

namespace App\Tests\Integration\Maintenance;

use App\Inventory\Application\Command\ReserveKey\ReserveKeyCommand;
use App\Inventory\Infrastructure\Schedule\ExpireReservationsTick;
use App\Inventory\Infrastructure\Schedule\ExpireReservationsTickHandler;
use App\Payment\Infrastructure\Schedule\ReconcilePaymentsTick;
use App\Payment\Infrastructure\Schedule\ReconcilePaymentsTickHandler;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

#[Group('M4.6')]
final class MaintenanceCommandsTest extends IntegrationTestCase
{
    public function testExpireReservationsCommandFreesTheKeyOfALapsedReservation(): void
    {
        $product = $this->seedProduct();
        $this->seedKeys($product->id, 1);
        // Reserved long ago (t0 is in May 2026): the real clock of the command is past the 15 minute deadline.
        $this->infra->reserveKey(new MockClock('2026-05-01T10:00:00Z'))(new ReserveKeyCommand(self::uuid(), $product->id->value));

        $tester = $this->tester('app:reservations:expire');
        self::assertSame(0, $tester->execute(['--limit' => '10']));

        self::assertStringContainsString('expired 1', $tester->getDisplay());
        self::assertSame(1, $this->countRows('product_keys', "status = 'available'"));
        self::assertSame(1, $this->countRows('key_reservations', "status = 'expired'"));

        $tester->execute(['--limit' => '10']);
        self::assertStringContainsString('expired 0', $tester->getDisplay(), 'a second sweep has nothing to do');
    }

    public function testCommandsRejectOutOfRangeOptions(): void
    {
        $expire = $this->tester('app:reservations:expire');
        self::assertSame(2, $expire->execute(['--limit' => '0']));
        self::assertSame(2, $expire->execute(['--limit' => '1001']));

        $reconcile = $this->tester('app:payments:reconcile');
        self::assertSame(2, $reconcile->execute(['--older-than' => '59']));
        self::assertSame(2, $reconcile->execute(['--limit' => '0']));
        self::assertStringContainsString('Invalid', $reconcile->getDisplay());
    }

    public function testReconcilePaymentsCommandRunsOnAnEmptyDatabase(): void
    {
        $tester = $this->tester('app:payments:reconcile');

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('checked 0, succeeded late 0, refunded 0', $tester->getDisplay());
    }

    public function testSchedulerTicksRunTheSameHandlers(): void
    {
        $product = $this->seedProduct();
        $this->seedKeys($product->id, 1);
        $this->infra->reserveKey(new MockClock('2026-05-01T10:00:00Z'))(new ReserveKeyCommand(self::uuid(), $product->id->value));

        $expire = self::getContainer()->get(ExpireReservationsTickHandler::class);
        $reconcile = self::getContainer()->get(ReconcilePaymentsTickHandler::class);

        $expire(new ExpireReservationsTick(10));
        $reconcile(new ReconcilePaymentsTick(300, 10));

        self::assertSame(1, $this->countRows('key_reservations', "status = 'expired'"));
    }

    private function tester(string $name): CommandTester
    {
        return new CommandTester((new Application(self::$kernel ?? throw new \LogicException('no kernel')))->find($name));
    }
}
