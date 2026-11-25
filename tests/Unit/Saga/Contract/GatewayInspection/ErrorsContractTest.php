<?php

declare(strict_types=1);

namespace App\Tests\Unit\Saga\Contract\GatewayInspection;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('M9.1')]
final class ErrorsContractTest extends TestCase
{
    /**
     * What the `inspect_gateway_charge` profile will list as `non_retryable_types` (config/packages/temporal.yaml, M9.4).
     * Until the profile exists this constant stands in for it; M9.4 replaces it with the value read from the profile.
     */
    private const array PROFILE_NON_RETRYABLE_TYPES = ['InvalidInspectionInput', 'UnsupportedContractVersion', 'GatewayRequestRejected'];

    #[Group('T-M9-04')]
    public function testNonRetryableTypesEqualTheContractFile(): void
    {
        $expected = [];

        foreach ($this->errors() as $error) {
            if ($error['nonRetryable']) {
                $expected[] = $error['type'];
            }
        }

        $actual = self::PROFILE_NON_RETRYABLE_TYPES;
        sort($expected);
        sort($actual);

        self::assertSame($expected, $actual);
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
