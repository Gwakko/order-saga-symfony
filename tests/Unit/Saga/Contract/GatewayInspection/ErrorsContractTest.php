<?php

declare(strict_types=1);

namespace App\Tests\Unit\Saga\Contract\GatewayInspection;

use App\Saga\Infrastructure\Temporal\Config\ActivityProfile;
use App\Saga\Infrastructure\Temporal\Config\ActivitySettings;
use App\Saga\Infrastructure\Temporal\Config\SagaConfiguration;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[Group('M9.1')]
final class ErrorsContractTest extends TestCase
{
    #[Group('T-M9-04')]
    public function testNonRetryableTypesEqualTheContractFile(): void
    {
        $expected = [];

        foreach ($this->errors() as $error) {
            if ($error['nonRetryable']) {
                $expected[] = $error['type'];
            }
        }

        $profile = $this->profile();
        $actual = $profile->nonRetryableTypes;
        sort($expected);
        sort($actual);

        self::assertSame($expected, $actual);
    }

    /** The list also reaches the options the workflow gives to the activity (what the server reads). */
    #[Group('T-M9-04')]
    public function testTheTypesReachTheRetryPolicy(): void
    {
        $options = $this->configuration()->activityOptions(ActivityProfile::InspectGatewayCharge);

        self::assertNotNull($options->retryOptions);
        self::assertEqualsCanonicalizing($this->profile()->nonRetryableTypes, $options->retryOptions->nonRetryableExceptions);
    }

    #[Group('T-M9-04')]
    public function testErrorsFileIsWellFormed(): void
    {
        $types = array_column($this->errors(), 'type');

        self::assertCount(6, $types);
        self::assertSame($types, array_values(array_unique($types)), 'Error types must be unique.');

        foreach ($types as $type) {
            self::assertMatchesRegularExpression('/^[A-Z][A-Za-z0-9]*$/', $type);
        }
    }

    private function profile(): ActivitySettings
    {
        return $this->configuration()->activities[ActivityProfile::InspectGatewayCharge->value];
    }

    /** The shipped `config/packages/temporal.yaml`, with the Go queue parameter filled in the way the container does. */
    private function configuration(): SagaConfiguration
    {
        $yaml = Yaml::parseFile(dirname(__DIR__, 5) . '/config/packages/temporal.yaml');
        self::assertIsArray($yaml);
        self::assertIsArray($yaml['parameters'] ?? null);
        $activities = $yaml['parameters']['temporal.activities'] ?? null;
        self::assertIsArray($activities);
        self::assertIsArray($activities['inspect_gateway_charge'] ?? null);
        $activities['inspect_gateway_charge']['task_queue'] = 'payment-go-test';

        return SagaConfiguration::fromParameters('order-saga', 86400, $activities, 900, 60);
    }

    /** @return list<array{type: string, nonRetryable: bool}> */
    private function errors(): array
    {
        $list = [];
        $errors = GoldenFiles::json('errors.json')['errors'] ?? null;
        self::assertIsArray($errors);

        foreach ($errors as $error) {
            self::assertIsArray($error);
            self::assertIsString($error['type'] ?? null);
            self::assertIsBool($error['nonRetryable'] ?? null);
            $list[] = ['type' => $error['type'], 'nonRetryable' => $error['nonRetryable']];
        }

        return $list;
    }
}
