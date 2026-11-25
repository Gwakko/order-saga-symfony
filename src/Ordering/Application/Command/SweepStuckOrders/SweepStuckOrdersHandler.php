<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\SweepStuckOrders;

use App\Ordering\Application\Command\MarkOrderManualReview\MarkOrderManualReviewCommand;
use App\Ordering\Application\Command\MarkOrderManualReview\MarkOrderManualReviewHandler;
use App\Ordering\Application\Exception\WorkflowStatusUnavailable;
use App\Ordering\Application\Port\SagaWorkflowKind;
use App\Ordering\Application\Port\StuckOrderFinder;
use App\Ordering\Application\Port\WorkflowStatusChecker;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\DomainError;
use App\Shared\Domain\FailureReason;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Sweeper for orders whose saga died without parking them (for example `MarkOrderManualReview` could not reach the
 * database): an order in an intermediate status, untouched for `olderThanSeconds`, with no running workflow, goes to
 * `ManualReview(SagaInterrupted)` through the same command the workflow uses. A running workflow is never touched, and
 * when the engine cannot be asked the order is left for the next run. Repeating the run is harmless: a parked order
 * is no longer in an intermediate status, and an order that moved on meanwhile is skipped.
 */
final readonly class SweepStuckOrdersHandler implements CommandHandler
{
    public function __construct(
        private StuckOrderFinder $finder,
        private WorkflowStatusChecker $workflows,
        private MarkOrderManualReviewHandler $manualReview,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(SweepStuckOrdersCommand $command): SweepStuckOrdersOutput
    {
        $before = \DateTimeImmutable::createFromInterface($this->clock->now())->modify(sprintf('-%d seconds', $command->olderThanSeconds));
        $candidates = $this->finder->findUpdatedBefore($before, $command->limit);
        $interrupted = 0;
        $stillRunning = 0;
        $skipped = 0;

        foreach ($candidates as $candidate) {
            try {
                if ($this->workflows->isRunning($candidate->orderId, self::kindOf($candidate->status))) {
                    ++$stillRunning;

                    continue;
                }

                ($this->manualReview)(new MarkOrderManualReviewCommand($candidate->orderId->value, FailureReason::SagaInterrupted, $candidate->status->value));
                ++$interrupted;
            } catch (WorkflowStatusUnavailable $e) {
                $this->logger->warning('Cannot tell whether the saga of an order is running; leaving it for the next sweep.', ['orderId' => $candidate->orderId->value, 'error' => $e->getMessage()]);
                ++$skipped;
            } catch (DomainError $e) {
                // The order moved on (or the workflow finished it: `OrderNotFound`, `ConcurrencyConflict`, an illegal transition) between the query and the transition: its new state stands.
                $this->logger->info('Stuck order changed under the sweeper; skipped.', ['orderId' => $candidate->orderId->value, 'error' => $e->getMessage()]);
                ++$skipped;
            }
        }

        return new SweepStuckOrdersOutput(count($candidates), $interrupted, $stillRunning, $skipped);
    }

    private static function kindOf(OrderStatus $status): SagaWorkflowKind
    {
        return match ($status) {
            OrderStatus::Refunding => SagaWorkflowKind::Refund,
            OrderStatus::Pending, OrderStatus::KeyReserved, OrderStatus::Paid, OrderStatus::Compensating,
            OrderStatus::Completed, OrderStatus::Cancelled, OrderStatus::Failed, OrderStatus::Refunded, OrderStatus::ManualReview => SagaWorkflowKind::Fulfillment,
        };
    }
}
