<?php

declare(strict_types=1);

namespace App\Tests\Workflow\Support;

use Temporal\Worker\InvocationFailure;
use Temporal\Worker\InvocationResult;

/**
 * The answers of one mocked activity, one per call, failures and completions mixed; the last one repeats. Stored in the
 * RoadRunner `kv` store by the test and consumed by the test worker (see SequencedActivityInvocationCache).
 */
final class InvocationSequence
{
    /** @param non-empty-list<InvocationResult|InvocationFailure> $items */
    public function __construct(private array $items) {}

    public function current(): InvocationResult|InvocationFailure
    {
        return $this->items[0];
    }

    public function advance(): void
    {
        if (count($this->items) > 1) {
            array_shift($this->items);
        }
    }
}
