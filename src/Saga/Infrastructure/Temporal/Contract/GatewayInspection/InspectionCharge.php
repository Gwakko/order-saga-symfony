<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Contract\GatewayInspection;

/** The charge the gateway reported (`charge` of output.schema.json). Currency is free text: the gateway may use a foreign one. */
final readonly class InspectionCharge
{
    /** @throws InspectionContractViolation */
    public function __construct(
        public string $chargeId,
        public int $amount,
        public string $currency,
        public ?string $declineReason,
    ) {
        if ($chargeId === '' || mb_strlen($chargeId) > 128) {
            throw InspectionContractViolation::because('charge.chargeId must be 1..128 characters');
        }

        if ($amount < 1) {
            throw InspectionContractViolation::because('charge.amount must be a positive integer');
        }

        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw InspectionContractViolation::because('charge.currency must be three capital letters');
        }

        if ($declineReason !== null && mb_strlen($declineReason) > 256) {
            throw InspectionContractViolation::because('charge.declineReason is longer than 256 characters');
        }
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $chargeId = $data['chargeId'] ?? null;
        $amount = $data['amount'] ?? null;
        $currency = $data['currency'] ?? null;

        if (!is_string($chargeId)) {
            throw InspectionContractViolation::because('charge.chargeId must be a string');
        }

        if (!is_int($amount)) {
            throw InspectionContractViolation::because('charge.amount must be an integer (minor units)');
        }

        if (!is_string($currency)) {
            throw InspectionContractViolation::because('charge.currency must be a string');
        }

        if (!array_key_exists('declineReason', $data)) {
            throw InspectionContractViolation::because('charge.declineReason is missing');
        }

        $declineReason = $data['declineReason'];

        if ($declineReason !== null && !is_string($declineReason)) {
            throw InspectionContractViolation::because('charge.declineReason must be a string or null');
        }

        return new self($chargeId, $amount, $currency, $declineReason);
    }

    /** @return array{chargeId: string, amount: int, currency: string, declineReason: ?string} */
    public function toArray(): array
    {
        return [
            'chargeId' => $this->chargeId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'declineReason' => $this->declineReason,
        ];
    }
}
