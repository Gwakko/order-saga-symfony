<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Ordering\Application\Exception\WorkflowStatusUnavailable;
use App\Ordering\Application\Port\SagaWorkflowKind;
use App\Ordering\Application\Port\WorkflowStatusChecker;
use App\Shared\Domain\Identifier\OrderId;

/**
 * Orders without an entry have no workflow (not running). `running()` marks a live one; `unavailable()` makes the
 * engine unreachable for that order.
 */
final class FakeWorkflowStatusChecker implements WorkflowStatusChecker
{
    /** @var array<string, true> */
    private array $running = [];

    /** @var array<string, true> */
    private array $unavailable = [];

    /** @var list<string> */
    public array $asked = [];

    public function running(OrderId $orderId, SagaWorkflowKind $kind = SagaWorkflowKind::Fulfillment): void
    {
        $this->running[self::key($orderId, $kind)] = true;
    }

    public function unavailable(OrderId $orderId): void
    {
        $this->unavailable[$orderId->value] = true;
    }

    public function isRunning(OrderId $orderId, SagaWorkflowKind $kind): bool
    {
        $this->asked[] = $kind->name . ':' . $orderId->value;

        if (isset($this->unavailable[$orderId->value])) {
            throw new WorkflowStatusUnavailable('temporal is down');
        }

        return isset($this->running[self::key($orderId, $kind)]);
    }

    private static function key(OrderId $orderId, SagaWorkflowKind $kind): string
    {
        return $kind->name . ':' . $orderId->value;
    }
}
