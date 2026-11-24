<?php

declare(strict_types=1);

namespace App\Tests\Workflow;

use App\Saga\Infrastructure\Temporal\Workflow\OrderFulfillmentWorkflow;
use PHPUnit\Framework\Attributes\Group;
use Temporal\Testing\Replay\Exception\NonDeterministicWorkflowException;
use Temporal\Testing\Replay\WorkflowReplayer;

/**
 * T-W-20 (EC-78): a history recorded from a real happy-path run is replayed against the CURRENT workflow code. A change
 * that reorders steps, drops one or adds an activity without `Workflow::getVersion` makes the replay fail with a
 * non-determinism error; that is how an incompatible change to a workflow with running instances is caught before deploy.
 *
 * The fixture is re-recorded on purpose only (see `testRecordHappyPathHistory`).
 */
#[Group('M6.4')]
final class OrderFulfillmentReplayTest extends OrderSagaWorkflowTestCase
{
    private const string FIXTURE = __DIR__ . '/Fixtures/history-happy.json';

    #[Group('T-W-20')]
    public function testHappyPathHistoryStillReplays(): void
    {
        self::assertFileExists(self::FIXTURE, 'Record it with `make wf-record-history`.');

        (new WorkflowReplayer())->replayFromJSON(OrderFulfillmentWorkflow::TYPE, self::FIXTURE);

        $this->addToAssertionCount(1); // replayFromJSON throws on any incompatibility
    }

    /**
     * Control for the test above: the same history with one recorded activity renamed (what a reordered or replaced step
     * looks like to the replayer) must be rejected, so a green T-W-20 really means "compatible".
     */
    #[Group('T-W-20')]
    public function testReplayDetectsAnIncompatibleChange(): void
    {
        $history = json_decode((string) file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($history);
        $changed = false;

        array_walk_recursive($history, static function (mixed &$value, int|string $key) use (&$changed): void {
            if (!$changed && $key === 'name' && $value === 'ChargePayment') {
                $value = 'ChargePaymentRenamed';
                $changed = true;
            }
        });
        self::assertTrue($changed, 'The fixture has no ChargePayment event.');

        $path = tempnam(sys_get_temp_dir(), 'history-');
        self::assertIsString($path);

        try {
            file_put_contents($path, json_encode($history, JSON_THROW_ON_ERROR));

            $this->expectException(NonDeterministicWorkflowException::class);
            (new WorkflowReplayer())->replayFromJSON(OrderFulfillmentWorkflow::TYPE, $path);
        } finally {
            unlink($path);
        }
    }

    /**
     * Not part of the suite: `make wf-record-history` (RECORD_HISTORY=1) runs the happy path against the test server and
     * overwrites the fixture with its history. Do it only after an intentional, versioned change of the workflow.
     */
    public function testRecordHappyPathHistory(): void
    {
        if (getenv('RECORD_HISTORY') !== '1') {
            self::markTestSkipped('Set RECORD_HISTORY=1 (make wf-record-history) to re-record tests/Workflow/Fixtures/history-happy.json.');
        }

        $this->mockHappyPath();
        $run = $this->startSaga('0190a5c4-0000-7000-8000-000000000020');
        self::assertSame('Completed', $this->resultOf($run));

        (new WorkflowReplayer())->downloadHistory(OrderFulfillmentWorkflow::TYPE, $run->getExecution(), self::FIXTURE);

        self::assertFileExists(self::FIXTURE);
    }
}
