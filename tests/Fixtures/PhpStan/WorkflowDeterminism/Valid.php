<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\PhpStan\WorkflowDeterminism;

use App\Saga\Infrastructure\Temporal\Config\SagaConfigurationHolder;
use Carbon\CarbonInterval;
use Temporal\Workflow;
use Temporal\Workflow\WorkflowInterface;

#[WorkflowInterface]
final class Valid
{
    private string $progress = 'starting';

    public function run(string $orderId): \Generator
    {
        $config = SagaConfigurationHolder::get();
        $now = Workflow::now();
        $deadline = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $options = $config->activityOptions(\App\Saga\Infrastructure\Temporal\Config\ActivityProfile::OrderStep);
        $interval = CarbonInterval::seconds(5);
        $this->progress = self::label($now->format('c') . $deadline->format('c') . $orderId . $interval->s . ($options->taskQueue ?? ''));

        yield Workflow::timer($interval);
    }

    private static function label(string $text): string
    {
        return strtoupper($text);
    }
}
