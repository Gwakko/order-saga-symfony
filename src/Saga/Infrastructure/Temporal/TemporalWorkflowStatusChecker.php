<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal;

use App\Ordering\Application\Exception\WorkflowStatusUnavailable;
use App\Ordering\Application\Port\SagaWorkflowKind;
use App\Ordering\Application\Port\WorkflowStatusChecker;
use App\Saga\Infrastructure\Temporal\Config\SagaConfiguration;
use App\Shared\Domain\Identifier\OrderId;
use Temporal\Client\GRPC\StatusCode;
use Temporal\Client\WorkflowClient;
use Temporal\Exception\Client\ServiceClientException;
use Temporal\Exception\Client\WorkflowNotFoundException;
use Temporal\Workflow\WorkflowExecutionStatus;

/**
 * `describe` of the latest run of `order-{id}` / `refund-{id}`: running or not. "Not found" means no workflow, so not
 * running; any other failure (Temporal down) is "unknown" and must not be read as "dead".
 */
final class TemporalWorkflowStatusChecker implements WorkflowStatusChecker
{
    private ?WorkflowClient $client = null;

    public function __construct(private readonly TemporalClientFactory $clients) {}

    public function isRunning(OrderId $orderId, SagaWorkflowKind $kind): bool
    {
        $workflowId = match ($kind) {
            SagaWorkflowKind::Fulfillment => SagaConfiguration::WORKFLOW_ID_PREFIX . $orderId->value,
            SagaWorkflowKind::Refund => SagaConfiguration::REFUND_WORKFLOW_ID_PREFIX . $orderId->value,
        };

        try {
            $client = $this->client ??= $this->clients->create();
            $status = $client->newUntypedRunningWorkflowStub($workflowId)->describe()->info->status;
        } catch (WorkflowNotFoundException) {
            return false;
        } catch (ServiceClientException $e) {
            // `describe` of an id that never existed is a plain gRPC NOT_FOUND, not a WorkflowNotFoundException.
            if ($e->getCode() === StatusCode::NOT_FOUND) {
                return false;
            }

            throw new WorkflowStatusUnavailable(sprintf('Cannot describe workflow %s: %s', $workflowId, $e->getMessage()), 0, $e);
        } catch (\Throwable $e) {
            throw new WorkflowStatusUnavailable(sprintf('Cannot describe workflow %s: %s', $workflowId, $e->getMessage()), 0, $e);
        }

        return $status === WorkflowExecutionStatus::Running;
    }
}
