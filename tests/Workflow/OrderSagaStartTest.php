<?php

declare(strict_types=1);

namespace App\Tests\Workflow;

use App\Saga\Infrastructure\Temporal\TemporalClientFactory;
use App\Saga\Infrastructure\Temporal\TemporalOrderSagaStarter;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Infrastructure\Id\SymfonyUuidGenerator;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

#[Group('M6.2')]
final class OrderSagaStartTest extends OrderSagaWorkflowTestCase
{
    /**
     * EC-11, INV-16: the same `OrderPlaced` delivered twice starts one workflow. The second start meets a finished
     * run (RejectDuplicate) and a running one alike, and both count as success.
     */
    #[Group('T-W-01')]
    public function testDuplicateStartIsSuccessAndLeavesOneRun(): void
    {
        $this->mockHappyPath();
        $orderId = (new SymfonyUuidGenerator())->next();
        $starter = new TemporalOrderSagaStarter(new TemporalClientFactory(WorkflowTestEnvironment::ADDRESS), self::configuration(), new NullLogger());

        $run = $this->startSaga($orderId); // the first delivery (through the client: the run id is needed below)

        $starter->start(OrderId::fromString($orderId)); // the duplicate while the first run may still be running
        self::assertSame('Completed', $this->resultOf($run));
        $starter->start(OrderId::fromString($orderId)); // the duplicate after it finished: RejectDuplicate

        $names = $this->scheduledActivities($run);

        self::assertSame(['ReserveKey', 'MarkOrderKeyReserved', 'ChargePayment', 'MarkOrderPaid', 'ConsumeReservation', 'CompleteOrder'], $names, 'One run, each step once.');
    }
}
