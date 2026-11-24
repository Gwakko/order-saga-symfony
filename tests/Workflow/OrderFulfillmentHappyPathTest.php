<?php

declare(strict_types=1);

namespace App\Tests\Workflow;

use PHPUnit\Framework\Attributes\Group;

#[Group('M6.2')]
final class OrderFulfillmentHappyPathTest extends OrderSagaWorkflowTestCase
{
    #[Group('T-W-18')]
    public function testActivitiesRunInTheDocumentedOrder(): void
    {
        $this->mockHappyPath();

        $run = $this->startSaga();

        self::assertSame('Completed', $this->resultOf($run));
        self::assertSame(
            ['ReserveKey', 'MarkOrderKeyReserved', 'ChargePayment', 'MarkOrderPaid', 'ConsumeReservation', 'CompleteOrder'],
            $this->scheduledActivities($run),
        );
        self::assertSame(3, $this->guardChecks($run), 'The cancellation guard runs before the first step, before the charge and before consuming.');
    }
}
