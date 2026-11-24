<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal;

use App\Ordering\Application\Port\OrderSagaStarter;
use App\Saga\Infrastructure\Temporal\Config\SagaConfiguration;
use App\Saga\Infrastructure\Temporal\Workflow\OrderFulfillmentWorkflow;
use App\Shared\Domain\Identifier\OrderId;
use Psr\Log\LoggerInterface;
use Temporal\Client\WorkflowClient;
use Temporal\Exception\Client\WorkflowExecutionAlreadyStartedException;

/**
 * Starts `order-{orderId}` (03_saga.md section 1). A workflow that already exists means an earlier delivery of the same
 * event started it: that is success, not an error (INV-16, EC-11). Any other failure (Temporal down: EC-58) propagates,
 * so Messenger retries the message and the order stays Pending meanwhile.
 *
 * The untyped stub is deliberate: the typed workflow interface declares `\Generator`, but a client stub returns the result.
 */
final class TemporalOrderSagaStarter implements OrderSagaStarter
{
    private ?WorkflowClient $client = null;

    public function __construct(
        private readonly TemporalClientFactory $clients,
        private readonly SagaConfiguration $configuration,
        private readonly LoggerInterface $logger,
    ) {}

    public function start(OrderId $orderId): void
    {
        $client = $this->client ??= $this->clients->create();
        $stub = $client->newUntypedWorkflowStub(OrderFulfillmentWorkflow::TYPE, $this->configuration->workflowOptions($orderId->value));

        try {
            $client->start($stub, $orderId->value);
        } catch (WorkflowExecutionAlreadyStartedException) {
            $this->logger->info('Order saga already started, nothing to do.', ['orderId' => $orderId->value]);
        }
    }
}
