<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Contract\GatewayInspection;

/**
 * Output of `InspectGatewayCharge` (output.schema.json). `fromArray` accepts only what the schema and the rules of
 * contracts/inspect-gateway-charge/v1/README.md allow; anything else is an InspectionContractViolation.
 */
final readonly class InspectionResult
{
    private const string CHECKED_AT_FORMAT = 'Y-m-d\TH:i:s.vP';

    /** @throws InspectionContractViolation */
    public function __construct(
        public InspectionOutcome $outcome,
        public ?InspectionCharge $charge,
        public bool $matchesExpected,
        public \DateTimeImmutable $checkedAt,
    ) {
        if (($charge === null) !== ($outcome === InspectionOutcome::NotFound)) {
            throw InspectionContractViolation::because('charge must be null if and only if outcome is not_found');
        }

        if ($matchesExpected && $outcome !== InspectionOutcome::Succeeded) {
            throw InspectionContractViolation::because('matchesExpected can be true only for outcome succeeded');
        }

        if ($checkedAt->format('P') !== '+00:00') {
            throw InspectionContractViolation::because('checkedAt must be in UTC');
        }
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $version = $data['contractVersion'] ?? null;

        if ($version !== InspectionRequest::CONTRACT_VERSION) {
            throw InspectionContractViolation::because(sprintf('unsupported contractVersion %s', is_int($version) ? (string) $version : get_debug_type($version)));
        }

        $outcomeValue = $data['outcome'] ?? null;
        $outcome = is_string($outcomeValue) ? InspectionOutcome::tryFrom($outcomeValue) : null;

        if ($outcome === null) {
            throw InspectionContractViolation::because('unknown outcome');
        }

        if (!array_key_exists('charge', $data)) {
            throw InspectionContractViolation::because('charge is missing');
        }

        $chargeData = $data['charge'];

        if ($chargeData !== null && !is_array($chargeData)) {
            throw InspectionContractViolation::because('charge must be an object or null');
        }

        $matches = $data['matchesExpected'] ?? null;

        if (!is_bool($matches)) {
            throw InspectionContractViolation::because('matchesExpected must be a boolean');
        }

        return new self(
            $outcome,
            $chargeData === null ? null : InspectionCharge::fromArray($chargeData),
            $matches,
            self::parseCheckedAt($data['checkedAt'] ?? null),
        );
    }

    /** @return array{contractVersion: int, outcome: string, charge: ?array{chargeId: string, amount: int, currency: string, declineReason: ?string}, matchesExpected: bool, checkedAt: string} */
    public function toArray(): array
    {
        return [
            'contractVersion' => InspectionRequest::CONTRACT_VERSION,
            'outcome' => $this->outcome->value,
            'charge' => $this->charge?->toArray(),
            'matchesExpected' => $this->matchesExpected,
            'checkedAt' => $this->checkedAt->format(self::CHECKED_AT_FORMAT),
        ];
    }

    private static function parseCheckedAt(mixed $value): \DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}\+00:00$/D', $value) !== 1) {
            throw InspectionContractViolation::because('checkedAt must be RFC 3339 UTC with milliseconds and +00:00');
        }

        $parsed = \DateTimeImmutable::createFromFormat(self::CHECKED_AT_FORMAT, $value);

        // createFromFormat rolls impossible dates over (2026-02-30 -> 2026-03-02): the round trip must be identical.
        if ($parsed === false || $parsed->format(self::CHECKED_AT_FORMAT) !== $value) {
            throw InspectionContractViolation::because('checkedAt is not a real date');
        }

        return $parsed;
    }
}
