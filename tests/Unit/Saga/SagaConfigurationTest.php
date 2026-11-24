<?php

declare(strict_types=1);

namespace App\Tests\Unit\Saga;

use App\Saga\Infrastructure\DependencyInjection\SagaConfigurationPass;
use App\Saga\Infrastructure\Temporal\Config\ActivityProfile;
use App\Saga\Infrastructure\Temporal\Config\InvalidSagaConfiguration;
use App\Saga\Infrastructure\Temporal\Config\SagaConfiguration;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Yaml\Yaml;
use Temporal\Common\IdReusePolicy;

/**
 * The timeout table of config/packages/temporal.yaml against the reservation TTL (EC-21, 03_saga.md section 3).
 */
#[Group('M6.2')]
final class SagaConfigurationTest extends TestCase
{
    /** 60 + 300 + 60 (ScheduleToClose of reserve, charge, consume) + 60 (charge guard) = 480 s, from the yaml table. */
    private const int STEP_DEADLINES = 480;

    #[Group('T-CFG-01')]
    public function testTtlMustExceedStepDeadlines(): void
    {
        // The shipped values: 900 s TTL is above 480 s.
        $this->build(900);
        $this->build(self::STEP_DEADLINES + 1);
        $this->addToAssertionCount(2);

        // Equal is not enough: the reservation must outlive the deadlines.
        foreach ([self::STEP_DEADLINES, self::STEP_DEADLINES - 1, 100] as $ttl) {
            try {
                $this->build($ttl);
                self::fail(sprintf('A TTL of %d s must be rejected.', $ttl));
            } catch (InvalidSagaConfiguration $e) {
                self::assertStringContainsString('Reservation TTL', $e->getMessage());
            }
        }
    }

    #[Group('T-CFG-01')]
    public function testContainerBuildFailsWhenTheTtlIsTooShort(): void
    {
        $container = new ContainerBuilder();
        foreach ($this->parameters() as $name => $value) {
            $container->setParameter($name, $value);
        }
        $container->setParameter('inventory.reservation_ttl_seconds', 300);

        $this->expectException(InvalidSagaConfiguration::class);

        (new SagaConfigurationPass())->process($container);
    }

    #[Group('T-CFG-01')]
    public function testContainerBuildPassesWithTheShippedValues(): void
    {
        $container = new ContainerBuilder();
        foreach ($this->parameters() as $name => $value) {
            $container->setParameter($name, $value);
        }

        (new SagaConfigurationPass())->process($container);

        $this->addToAssertionCount(1);
    }

    /** The SDK reads a bare integer as milliseconds: seconds from the config must arrive as seconds. */
    #[Group('T-CFG-01')]
    public function testSecondsFromConfigBecomeIntervalsNotMilliseconds(): void
    {
        $configuration = $this->build(900);

        $reserve = $configuration->activityOptions(ActivityProfile::ReserveKey);
        self::assertSame(5, $reserve->startToCloseTimeout->s);
        self::assertSame(60, $reserve->scheduleToCloseTimeout->s);
        self::assertNotNull($reserve->retryOptions);
        self::assertSame(['App\Inventory\Application\Exception\OutOfStock', 'App\Shared\Application\Exception\OrderNotFound'], $reserve->retryOptions->nonRetryableExceptions);

        $charge = $configuration->activityOptions(ActivityProfile::ChargePayment);
        self::assertSame(15, $charge->startToCloseTimeout->s);
        self::assertSame(300, $charge->scheduleToCloseTimeout->s);
    }

    public function testWorkflowIdIsOrderPrefixedAndDuplicatesAreRejected(): void
    {
        $options = $this->build(900)->workflowOptions('0194f3a0-0000-7000-8000-000000000001');

        self::assertSame('order-0194f3a0-0000-7000-8000-000000000001', $options->workflowId);
        self::assertSame('order-saga', $options->taskQueue);
        self::assertSame(IdReusePolicy::RejectDuplicate->value, $options->workflowIdReusePolicy);
        self::assertSame(86400, $options->workflowExecutionTimeout->s);
    }

    public function testRefundWorkflowIdIsRefundPrefixedAndDuplicatesAreRejected(): void
    {
        $options = $this->build(900)->refundWorkflowOptions('0194f3a0-0000-7000-8000-000000000001');

        self::assertSame('refund-0194f3a0-0000-7000-8000-000000000001', $options->workflowId);
        self::assertSame('order-saga', $options->taskQueue);
        self::assertSame(IdReusePolicy::RejectDuplicate->value, $options->workflowIdReusePolicy);
        self::assertSame(691200, $options->workflowExecutionTimeout->s);
    }

    /** The compensation may retry for 7 days before the saga parks the order, so the run timeouts must outlast that. */
    public function testRunTimeoutsOutlastTheLongestCompensation(): void
    {
        $parameters = $this->parameters();
        $activities = $this->activities();
        $refundDeadline = $activities['refund_payment']['schedule_to_close'];

        self::assertIsInt($refundDeadline);
        self::assertGreaterThan($refundDeadline, $parameters['temporal.order_workflow.execution_timeout_seconds']);
        self::assertGreaterThan($refundDeadline, $parameters['temporal.refund_workflow.execution_timeout_seconds']);
    }

    public function testStartToCloseAboveScheduleToCloseIsRejected(): void
    {
        $activities = $this->activities();
        $activities['charge_payment']['start_to_close'] = 301;

        $this->expectException(InvalidSagaConfiguration::class);

        SagaConfiguration::fromParameters('order-saga', 86400, $activities, 900, 60);
    }

    public function testMissingProfileIsRejected(): void
    {
        $activities = $this->activities();
        unset($activities['refund_payment']);

        $this->expectException(InvalidSagaConfiguration::class);

        SagaConfiguration::fromParameters('order-saga', 86400, $activities, 900, 60);
    }

    public function testNonRetryableMustBeAnExceptionClass(): void
    {
        $activities = $this->activities();
        $activities['reserve_key']['non_retryable'] = ['App\NoSuchException'];

        $this->expectException(InvalidSagaConfiguration::class);

        SagaConfiguration::fromParameters('order-saga', 86400, $activities, 900, 60);
    }

    private function build(int $ttl): SagaConfiguration
    {
        return SagaConfiguration::fromParameters('order-saga', 86400, $this->activities(), $ttl, 60);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function activities(): array
    {
        $activities = $this->parameters()['temporal.activities'];
        self::assertIsArray($activities);

        /** @var array<string, array<string, mixed>> $activities */
        return $activities;
    }

    /**
     * @return array<string, array<mixed>|bool|float|int|string|null>
     */
    private function parameters(): array
    {
        $yaml = Yaml::parseFile(dirname(__DIR__, 3) . '/config/packages/temporal.yaml');
        self::assertIsArray($yaml);
        $parameters = $yaml['parameters'] ?? null;
        self::assertIsArray($parameters);

        /** @var array<string, array<mixed>|bool|float|int|string|null> $parameters */
        return $parameters + ['inventory.reservation_ttl_seconds' => 900, 'payment.charge_guard_seconds' => 60];
    }
}
