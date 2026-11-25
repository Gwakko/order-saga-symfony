<?php

declare(strict_types=1);

namespace App\Tests\Unit\Saga\Contract\GatewayInspection;

use App\Saga\Infrastructure\Temporal\Contract\GatewayInspection\InspectionContractViolation;
use App\Saga\Infrastructure\Temporal\Contract\GatewayInspection\InspectionOutcome;
use App\Saga\Infrastructure\Temporal\Contract\GatewayInspection\InspectionResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('M9.1')]
final class InspectionResultTest extends TestCase
{
    #[Group('T-M9-02')]
    #[DataProvider('goldenOutputs')]
    public function testEveryGoldenOutputIsParsedAndRoundTrips(string $file, InspectionOutcome $outcome, bool $matches, bool $hasCharge): void
    {
        $result = InspectionResult::fromArray(GoldenFiles::json($file));

        self::assertSame($outcome, $result->outcome);
        self::assertSame($matches, $result->matchesExpected);
        self::assertSame($hasCharge, $result->charge !== null);
        self::assertSame('2026-11-25T10:15:30.123+00:00', $result->toArray()['checkedAt']);
        self::assertEquals(GoldenFiles::json($file), $result->toArray());
    }

    /** @return iterable<string, array{string, InspectionOutcome, bool, bool}> */
    public static function goldenOutputs(): iterable
    {
        yield 'succeeded' => ['output.succeeded.json', InspectionOutcome::Succeeded, true, true];
        yield 'mismatch' => ['output.mismatch.json', InspectionOutcome::Succeeded, false, true];
        yield 'declined' => ['output.declined.json', InspectionOutcome::Declined, false, true];
        yield 'not found' => ['output.not_found.json', InspectionOutcome::NotFound, false, false];
    }

    #[Group('T-M9-02')]
    public function testTheChargeKeepsAmountAndDeclineReason(): void
    {
        $succeeded = InspectionResult::fromArray(GoldenFiles::json('output.succeeded.json'));
        $declined = InspectionResult::fromArray(GoldenFiles::json('output.declined.json'));

        self::assertSame(1999, $succeeded->charge?->amount);
        self::assertSame('EUR', $succeeded->charge->currency);
        self::assertNull($succeeded->charge->declineReason);
        self::assertSame('insufficient_funds', $declined->charge?->declineReason);
    }

    #[Group('T-M9-02')]
    public function testAForeignCurrencyIsAnAnswerNotAViolation(): void
    {
        $data = GoldenFiles::json('output.mismatch.json');
        $data['charge'] = ['chargeId' => 'ch_01', 'amount' => 1999, 'currency' => 'GBP', 'declineReason' => null];

        self::assertSame('GBP', InspectionResult::fromArray($data)->charge?->currency);
    }

    /**
     * @param callable(array<mixed>): array<mixed> $mutate
     */
    #[Group('T-M9-02')]
    #[DataProvider('violations')]
    public function testAnswersOutsideTheContractAreRejected(string $file, callable $mutate): void
    {
        $this->expectException(InspectionContractViolation::class);

        InspectionResult::fromArray($mutate(GoldenFiles::json($file)));
    }

    /** @return iterable<string, array{string, callable(array<mixed>): array<mixed>}> */
    public static function violations(): iterable
    {
        $s = 'output.succeeded.json';

        yield 'charge present for not_found' => ['output.not_found.json', static fn(array $d): array => [...$d, 'charge' => ['chargeId' => 'c', 'amount' => 1, 'currency' => 'EUR', 'declineReason' => null]]];
        yield 'charge null for succeeded' => [$s, static fn(array $d): array => [...$d, 'charge' => null]];
        yield 'amount as float' => [$s, static fn(array $d): array => [...$d, 'charge' => [...self::chargeOf($d), 'amount' => 19.99]]];
        yield 'amount as huge float' => [$s, static fn(array $d): array => [...$d, 'charge' => [...self::chargeOf($d), 'amount' => 1.0E+20]]];
        yield 'amount as string' => [$s, static fn(array $d): array => [...$d, 'charge' => [...self::chargeOf($d), 'amount' => '1999']]];
        yield 'amount zero' => [$s, static fn(array $d): array => [...$d, 'charge' => [...self::chargeOf($d), 'amount' => 0]]];
        yield 'amount negative' => [$s, static fn(array $d): array => [...$d, 'charge' => [...self::chargeOf($d), 'amount' => -5]]];
        yield 'unknown outcome' => [$s, static fn(array $d): array => [...$d, 'outcome' => 'pending']];
        yield 'outcome with other case' => [$s, static fn(array $d): array => [...$d, 'outcome' => 'Succeeded']];
        yield 'outcome missing' => [$s, static function (array $d): array {
            unset($d['outcome']);

            return $d;
        }];
        yield 'contract version 2' => [$s, static fn(array $d): array => [...$d, 'contractVersion' => 2]];
        yield 'contract version as string' => [$s, static fn(array $d): array => [...$d, 'contractVersion' => '1']];
        yield 'matches as int' => [$s, static fn(array $d): array => [...$d, 'matchesExpected' => 1]];
        yield 'matches true for declined' => ['output.declined.json', static fn(array $d): array => [...$d, 'matchesExpected' => true]];
        yield 'checkedAt with Z' => [$s, static fn(array $d): array => [...$d, 'checkedAt' => '2026-11-25T10:15:30.123Z']];
        yield 'checkedAt with +02:00' => [$s, static fn(array $d): array => [...$d, 'checkedAt' => '2026-11-25T12:15:30.123+02:00']];
        yield 'checkedAt without milliseconds' => [$s, static fn(array $d): array => [...$d, 'checkedAt' => '2026-11-25T10:15:30+00:00']];
        yield 'checkedAt impossible date' => [$s, static fn(array $d): array => [...$d, 'checkedAt' => '2026-02-30T10:15:30.123+00:00']];
        yield 'checkedAt not a string' => [$s, static fn(array $d): array => [...$d, 'checkedAt' => 1_764_065_730]];
        yield 'lowercase charge currency' => [$s, static fn(array $d): array => [...$d, 'charge' => [...self::chargeOf($d), 'currency' => 'eur']]];
        yield 'empty charge id' => [$s, static fn(array $d): array => [...$d, 'charge' => [...self::chargeOf($d), 'chargeId' => '']]];
        yield 'decline reason too long' => [$s, static fn(array $d): array => [...$d, 'charge' => [...self::chargeOf($d), 'declineReason' => str_repeat('x', 257)]]];
        yield 'decline reason as int' => [$s, static fn(array $d): array => [...$d, 'charge' => [...self::chargeOf($d), 'declineReason' => 5]]];
        yield 'charge is a list of scalars' => [$s, static fn(array $d): array => [...$d, 'charge' => 'ch_01']];
    }

    #[Group('T-M9-02')]
    public function testUnknownExtraFieldsAreIgnored(): void
    {
        $data = GoldenFiles::json('output.succeeded.json');
        $data['addedInLaterMinorVersion'] = ['x' => 1];

        self::assertSame(InspectionOutcome::Succeeded, InspectionResult::fromArray($data)->outcome);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private static function chargeOf(array $data): array
    {
        $charge = $data['charge'];

        return is_array($charge) ? $charge : [];
    }
}
