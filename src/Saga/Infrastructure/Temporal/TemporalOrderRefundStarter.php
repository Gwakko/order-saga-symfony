<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal;

use App\Ordering\Application\Port\OrderRefundStarter;
use App\Saga\Infrastructure\Temporal\Config\SagaConfiguration;
use App\Saga\Infrastructure\Temporal\Workflow\RefundWorkflow;
use App\Shared\Domain\Identifier\OrderId;
use Psr\Log\LoggerInterface;
use Temporal\Client\WorkflowClient;
use Temporal\Exception\Client\WorkflowExecutionAlreadyStartedException;

/**
 * Starts `refund-{orderId}` (03_saga.md section 1). A second start for the same order meets the reject-duplicate
 * policy and counts as success: the first run does the refund, the gateway sees one request (EC-61).
 */
final class TemporalOrderRefundStarter implements OrderRefundStarter
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
        $stub = $client->newUntypedWorkflowStub(RefundWorkflow::TYPE, $this->configuration->refundWorkflowOptions($orderId->value));

        try {
            $client->start($stub, $orderId->value);
        } catch (WorkflowExecutionAlreadyStartedException) {
            $this->logger->info('Refund workflow already started, nothing to do.', ['orderId' => $orderId->value]);
        }
    }
}
