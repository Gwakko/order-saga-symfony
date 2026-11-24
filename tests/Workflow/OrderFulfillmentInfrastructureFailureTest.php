<?php

declare(strict_types=1);

namespace App\Tests\Workflow;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Temporal\DataConverter\DataConverter;
use Temporal\DataConverter\EncodedValues;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Workflow\WorkflowRunInterface;

/**
 * A step that keeps failing for a non-business reason (the database is down for longer than the step's ScheduleToClose)
 * must not leave the order in an undefined state: nothing is compensated blindly and the order is parked for an
 * operator (`ManualReview`, reason `SagaInterrupted`, M6.4). The payment step has its own rule (EC-34), see T-W-07.
 */
#[Group('M6.4')]
final class OrderFulfillmentInfrastructureFailureTest extends OrderSagaWorkflowTestCase
{
    /**
     * @return iterable<string, array{non-empty-string, list<string>}>
     */
    public static function failingSteps(): iterable
    {
        yield 'reserve key' => ['ReserveKey', ['ReserveKey', 'MarkOrderManualReview']];
        yield 'mark key reserved' => ['MarkOrderKeyReserved', ['ReserveKey', 'MarkOrderKeyReserved', 'MarkOrderManualReview']];
        yield 'mark paid' => ['MarkOrderPaid', ['ReserveKey', 'MarkOrderKeyReserved', 'ChargePayment', 'MarkOrderPaid', 'MarkOrderManualReview']];
        yield 'consume reservation' => ['ConsumeReservation', ['ReserveKey', 'MarkOrderKeyReserved', 'ChargePayment', 'MarkOrderPaid', 'ConsumeReservation', 'MarkOrderManualReview']];
        yield 'complete order' => ['CompleteOrder', ['ReserveKey', 'MarkOrderKeyReserved', 'ChargePayment', 'MarkOrderPaid', 'ConsumeReservation', 'CompleteOrder', 'MarkOrderManualReview']];
    }

    /**
     * @param non-empty-string $activity
     * @param list<string> $expectedActivities
     */
    #[DataProvider('failingSteps')]
    public function testExhaustedRetriesParkTheOrderForAnOperator(string $activity, array $expectedActivities): void
    {
        $this->mockHappyPath();
        $this->activityMocks->expectFailure($activity, new \RuntimeException('database is down'));

        $run = $this->startSaga();

        self::assertSame('ManualReview', $this->resultOf($run));
        self::assertSame($expectedActivities, $this->scheduledActivities($run), 'no refund or release is run blindly');
        self::assertSame(['SagaInterrupted', $activity], $this->manualReviewArguments($run));
    }

    /**
     * The reason and the step the saga passed to `MarkOrderManualReview` (arguments 1 and 2), read from the history.
     *
     * @param WorkflowRunInterface<mixed> $run
     *
     * @return list<string>
     */
    private function manualReviewArguments(WorkflowRunInterface $run): array
    {
        foreach ($this->workflowClient->getWorkflowHistory($run->getExecution())->getEvents() as $event) {
            $attributes = $event->getActivityTaskScheduledEventAttributes();

            if ($event->getEventType() === EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED && $attributes?->getActivityType()?->getName() === 'MarkOrderManualReview') {
                $input = $attributes->getInput() ?? throw new \UnexpectedValueException('No input.');
                $values = EncodedValues::fromPayloads($input, DataConverter::createDefault());
                $reason = $values->getValue(1, 'string');
                $step = $values->getValue(2, 'string');

                return [is_string($reason) ? $reason : '', is_string($step) ? $step : ''];
            }
        }

        throw new \LogicException('The workflow did not park the order.');
    }
}
