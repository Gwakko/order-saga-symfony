<?php

declare(strict_types=1);

namespace App\Tests\Integration\Maintenance;

use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderStatus;
use App\Ordering\Infrastructure\Persistence\DbalStuckOrderFinder;
use App\Ordering\Infrastructure\Schedule\SweepStuckOrdersTick;
use App\Ordering\Infrastructure\Schedule\SweepStuckOrdersTickHandler;
use App\Saga\Infrastructure\Temporal\Config\SagaConfiguration;
use App\Saga\Infrastructure\Temporal\TemporalClientFactory;
use App\Saga\Infrastructure\Temporal\TemporalOrderSagaStarter;
use App\Shared\Domain\FailureReason;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\PaymentId;
use App\Shared\Domain\Identifier\ReservationId;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Temporal\Client\WorkflowClient;

/**
 * M8a sweeper against Postgres and the dev Temporal server (workflows on the test queue, no worker, terminated in
 * tearDown). The orders were last changed in May 2026, which is old for the real clock of the console command.
 */
#[Group('M8a')]
final class SweepStuckOrdersTest extends IntegrationTestCase
{
    /** @var list<non-empty-string> */
    private array $started = [];

    protected function tearDown(): void
    {
        foreach ($this->started as $workflowId) {
            try {
                $this->client()->newUntypedRunningWorkflowStub($workflowId)->terminate('integration test cleanup');
            } catch (\Throwable) {
                // Already gone.
            }
        }

        parent::tearDown();
    }

    private function paidOrder(): Order
    {
        $order = $this->placeOrder();
        $this->infra->orders->save($order);
        $paid = $this->infra->orders->get($order->id)
            ->markKeyReserved(ReservationId::fromString(self::uuid()), $this->t0->modify('+1 minute'))
            ->markPaid(PaymentId::fromString(self::uuid()), $this->t0->modify('+2 minutes'));
        $this->infra->orders->save($paid);

        return $this->infra->orders->get($order->id);
    }

    private function statusOf(OrderId $id): OrderStatus
    {
        return $this->infra->orders->get($id)->status;
    }

    private function startWorkflow(OrderId $id): void
    {
        $configuration = self::getContainer()->get(SagaConfiguration::class);
        (new TemporalOrderSagaStarter(new TemporalClientFactory($this->temporalAddress()), $configuration, new NullLogger()))->start($id);
        $this->started[] = 'order-' . $id->value;
    }

    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::$kernel ?? throw new \LogicException('no kernel')))->find('app:orders:sweep-stuck'));
    }

    #[Group('T-M8A-30')]
    public function testFinderReturnsOnlyOldOrdersInIntermediateStatuses(): void
    {
        $old = $this->paidOrder();
        $fresh = $this->paidOrder();
        $this->connection->executeStatement('UPDATE orders SET updated_at = now() WHERE id = :id', ['id' => $fresh->id->value]);
        $done = $this->placeOrder();
        $this->infra->orders->save($done->cancelBeforeStart($this->t0->modify('+1 minute')));
        // `save` above stores a new order with the transition applied to the placed one, so it is a cancelled order.

        $found = (new DbalStuckOrderFinder($this->connection))->findUpdatedBefore(new \DateTimeImmutable('now - 30 minutes'), 10);

        self::assertSame([$old->id->value], array_map(static fn($stuck): string => $stuck->orderId->value, $found));
        self::assertSame(OrderStatus::Paid, $found[0]->status);
    }

    #[Group('T-M8A-31')]
    public function testCommandParksAnOrderWhoseWorkflowIsGoneAndLeavesALiveOne(): void
    {
        $dead = $this->paidOrder();
        $alive = $this->paidOrder();
        $neverStarted = $this->paidOrder();
        $this->startWorkflow($dead->id);
        $this->client()->newUntypedRunningWorkflowStub('order-' . $dead->id->value)->terminate('simulated crash');
        $this->startWorkflow($alive->id);

        $tester = $this->tester();
        self::assertSame(0, $tester->execute(['--older-than' => '1800']));

        self::assertStringContainsString('checked 3, moved to manual review 2, still running 1, skipped 0', $tester->getDisplay());
        self::assertSame(OrderStatus::ManualReview, $this->statusOf($dead->id), 'terminated workflow');
        self::assertSame(OrderStatus::ManualReview, $this->statusOf($neverStarted->id), 'no workflow at all');
        self::assertSame(OrderStatus::Paid, $this->statusOf($alive->id), 'live workflow is not touched');
        self::assertSame(FailureReason::SagaInterrupted, $this->infra->orders->get($dead->id)->failureReason);
        self::assertSame(2, $this->countRows('outbox_messages', "event_type = 'OrderNeedsManualReview'"));
    }

    #[Group('T-M8A-32')]
    public function testSecondRunAndSchedulerTickAreIdempotent(): void
    {
        $order = $this->paidOrder();

        $tester = $this->tester();
        $tester->execute([]);
        self::assertSame(OrderStatus::ManualReview, $this->statusOf($order->id));
        $outbox = $this->countRows('outbox_messages');
        $version = $this->infra->orders->get($order->id)->version;

        $tester->execute([]);
        self::assertStringContainsString('checked 0, moved to manual review 0', $tester->getDisplay());
        self::getContainer()->get(SweepStuckOrdersTickHandler::class)(new SweepStuckOrdersTick(10));

        self::assertSame($outbox, $this->countRows('outbox_messages'));
        self::assertSame($version, $this->infra->orders->get($order->id)->version);
    }

    #[Group('T-M8A-33')]
    public function testCommandRejectsOutOfRangeOptions(): void
    {
        $tester = $this->tester();

        self::assertSame(2, $tester->execute(['--older-than' => '59']));
        self::assertSame(2, $tester->execute(['--limit' => '0']));
        self::assertStringContainsString('Invalid', $tester->getDisplay());
    }

    private function client(): WorkflowClient
    {
        return (new TemporalClientFactory($this->temporalAddress()))->create();
    }

    /**
     * @return non-empty-string
     */
    private function temporalAddress(): string
    {
        $address = $_SERVER['TEMPORAL_ADDRESS'] ?? $_ENV['TEMPORAL_ADDRESS'] ?? 'temporal:7233';

        return is_string($address) && $address !== '' ? $address : 'temporal:7233';
    }
}
