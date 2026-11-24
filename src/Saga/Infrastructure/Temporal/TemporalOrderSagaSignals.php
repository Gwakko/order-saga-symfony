<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal;

use App\Ordering\Application\Port\OrderSagaSignals;
use App\Saga\Infrastructure\Temporal\Config\SagaConfiguration;
use App\Saga\Infrastructure\Temporal\Workflow\OrderFulfillmentWorkflow;
use App\Shared\Domain\Identifier\OrderId;
use Psr\Log\LoggerInterface;
use Temporal\Client\WorkflowClient;
use Temporal\Exception\Client\WorkflowNotFoundException;

/**
 * Sends `requestCancellation` to `order-{orderId}` (03_saga.md section 5). The cancellation flag is committed before
 * this runs, so there is no race with the start of the saga: a workflow that does not exist yet (or is already
 * finished) answers "not found", and a workflow that starts later reads the flag in its first guard. Any other failure
 * (Temporal down: EC-44) propagates; `CancelOrderHandler` logs it and still answers 202.
 */
final class TemporalOrderSagaSignals implements OrderSagaSignals
{
    private ?WorkflowClient $client = null;

    public function __construct(
        private readonly TemporalClientFactory $clients,
        private readonly LoggerInterface $logger,
    ) {}

    public function requestCancellation(OrderId $orderId): void
    {
        $client = $this->client ??= $this->clients->create();
        $stub = $client->newRunningWorkflowStub(OrderFulfillmentWorkflow::class, SagaConfiguration::WORKFLOW_ID_PREFIX . $orderId->value);

        try {
            $stub->requestCancellation();
        } catch (WorkflowNotFoundException) {
            $this->logger->info('No running order saga to signal: the flag in the database decides.', ['orderId' => $orderId->value]);
        }
    }
}
