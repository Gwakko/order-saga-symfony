<?php

declare(strict_types=1);

namespace App\Tests\Unit\Saga;

use App\Saga\Infrastructure\DependencyInjection\SagaConfigurationPass;
use App\Saga\Infrastructure\Temporal\Config\ActivityProfile;
use App\Saga\Infrastructure\Temporal\Config\ActivitySettings;
use App\Saga\Infrastructure\Temporal\Config\InvalidSagaConfiguration;
use App\Saga\Infrastructure\Temporal\Config\SagaConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
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
    /**
     * ScheduleToClose of reserve (60) + charge (300) + order_step for PrepareChargeInspection (60) + inspect_gateway_charge (60)
     * + charge_recovery (60) + consume (60) + the charge guard (60) = 660 s, from the yaml table. The inspection terms count
     * whether or not the switch is on.
     */
    private const int STEP_DEADLINES = 660;

    #[Group('T-CFG-01')]
    public function testTtlMustExceedStepDeadlines(): void
    {
        // The shipped values: 900 s TTL is above 660 s.
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

    /** T-M9-05: queue, ScheduleToStart and failure type names of a profile reach the SDK's ActivityOptions. */
    #[Group('T-M9-05')]
    public function testProfileOptionsReachTheActivityOptions(): void
    {
        $configuration = $this->build(900);

        $inspect = $configuration->activityOptions(ActivityProfile::InspectGatewayCharge);
        self::assertSame('payment-go-test', $inspect->taskQueue);
        self::assertSame(20, $inspect->scheduleToStartTimeout->s);
        self::assertSame(15, $inspect->startToCloseTimeout->s);
        self::assertSame(60, $inspect->scheduleToCloseTimeout->s);
        self::assertNotNull($inspect->retryOptions);
        self::assertSame(['InvalidInspectionInput', 'UnsupportedContractVersion', 'GatewayRequestRejected'], $inspect->retryOptions->nonRetryableExceptions);
        self::assertSame(2, $inspect->retryOptions->initialInterval?->s);
        self::assertSame(10, $inspect->retryOptions->maximumInterval?->s);

        $recovery = $configuration->activityOptions(ActivityProfile::ChargeRecovery);
        self::assertSame('order-saga', $recovery->taskQueue, 'A profile without task_queue runs on the saga queue.');
        self::assertSame(60, $recovery->scheduleToCloseTimeout->s);
        self::assertNotNull($recovery->retryOptions);
        self::assertSame(
            $configuration->activityOptions(ActivityProfile::ChargePayment)->retryOptions?->nonRetryableExceptions,
            $recovery->retryOptions->nonRetryableExceptions,
            'The repeated charge fails for the same business reasons as the first one.',
        );

        $charge = $configuration->activityOptions(ActivityProfile::ChargePayment);
        self::assertSame(0, $charge->scheduleToStartTimeout->s, 'ScheduleToStart is only set where the profile has it.');
    }

    /** T-M9-05 */
    #[Group('T-M9-05')]
    public function testProgrammaticSettingsAreValidatedToo(): void
    {
        $base = $this->build(900)->activities['inspect_gateway_charge'];
        foreach ([
            'empty queue' => self::inspection($base, '', 20, []),
            'zero ScheduleToStart' => self::inspection($base, 'go', 0, []),
            'ScheduleToStart above ScheduleToClose' => self::inspection($base, 'go', 61, []),
            'type with a backslash' => self::inspection($base, 'go', 20, ['App\\Exception\\Foo']),
            'lowercase type' => self::inspection($base, 'go', 20, ['gatewayError']),
            'empty type' => self::inspection($base, 'go', 20, ['']),
        ] as $case => $settings) {
            try {
                new SagaConfiguration('order-saga', 86400, ['inspect_gateway_charge' => $settings] + $this->build(900)->activities);
                self::fail($case . ' must be rejected.');
            } catch (InvalidSagaConfiguration) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @param list<string> $types */
    private static function inspection(ActivitySettings $base, ?string $queue, ?int $scheduleToStart, array $types): ActivitySettings
    {
        return new ActivitySettings(
            $base->startToCloseSeconds,
            $base->scheduleToCloseSeconds,
            $base->initialIntervalSeconds,
            $base->backoff,
            $base->maxIntervalSeconds,
            $base->maxAttempts,
            [],
            $queue,
            $scheduleToStart,
            $types,
        );
    }

    /**
     * @param array<string, mixed> $override keys of the `inspect_gateway_charge` row of the yaml table
     */
    #[Group('T-M9-05')]
    #[DataProvider('brokenInspectionRows')]
    public function testBrokenInspectionRowsAreRejected(array $override): void
    {
        $activities = $this->activities();
        $activities['inspect_gateway_charge'] = $override + $activities['inspect_gateway_charge'];

        $this->expectException(InvalidSagaConfiguration::class);

        SagaConfiguration::fromParameters('order-saga', 86400, $activities, 900, 60);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function brokenInspectionRows(): iterable
    {
        yield 'empty task queue' => [['task_queue' => '']];
        yield 'task queue is not a string' => [['task_queue' => 7]];
        yield 'schedule_to_start is not an integer' => [['schedule_to_start' => '20']];
        yield 'schedule_to_start is zero' => [['schedule_to_start' => 0]];
        yield 'schedule_to_start above schedule_to_close' => [['schedule_to_start' => 61]];
        yield 'non_retryable_types is not a list' => [['non_retryable_types' => 'GatewayRequestRejected']];
        yield 'a type is a class name' => [['non_retryable_types' => ['App\\Exception\\Foo']]];
        yield 'a type starts lowercase' => [['non_retryable_types' => ['gatewayRequestRejected']]];
        yield 'a type is not a string' => [['non_retryable_types' => [1]]];
    }

    /** T-M9-05, EC-109: a profile the saga needs is missing. */
    #[Group('T-M9-05')]
    public function testMissingInspectionProfileIsRejected(): void
    {
        foreach (['inspect_gateway_charge', 'charge_recovery'] as $profile) {
            $activities = $this->activities();
            unset($activities[$profile]);

            try {
                SagaConfiguration::fromParameters('order-saga', 86400, $activities, 900, 60);
                self::fail($profile . ' must be required.');
            } catch (InvalidSagaConfiguration) {
                $this->addToAssertionCount(1);
            }
        }
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

        // The yaml refers to the Go queue by a parameter that the container resolves; the test names it here.
        self::assertIsArray($activities['inspect_gateway_charge'] ?? null);
        $activities['inspect_gateway_charge']['task_queue'] = 'payment-go-test';

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
