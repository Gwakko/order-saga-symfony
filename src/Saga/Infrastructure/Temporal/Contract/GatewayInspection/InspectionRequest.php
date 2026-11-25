<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Contract\GatewayInspection;

use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;

/** Input of `InspectGatewayCharge` (input.schema.json). Pure mapping: no clock, no randomness. */
final readonly class InspectionRequest
{
    public const int CONTRACT_VERSION = 1;

    public const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** @throws InspectionContractViolation */
    public function __construct(
        public string $orderId,
        public string $idempotencyKey,
        public int $amount,
        public Currency $currency,
    ) {
        if (preg_match(self::UUID_PATTERN, $orderId) !== 1) {
            throw InspectionContractViolation::because('orderId is not a UUID');
        }

        if (preg_match('/^[A-Za-z0-9-]{1,64}$/D', $idempotencyKey) !== 1) {
            throw InspectionContractViolation::because('idempotencyKey must be 1..64 characters of [A-Za-z0-9-]');
        }

        if ($amount < 1 || $amount > Money::MAX_AMOUNT) {
            throw InspectionContractViolation::because(sprintf('expected amount must be 1..%d minor units', Money::MAX_AMOUNT));
        }
    }

    /** @return array{contractVersion: int, orderId: string, idempotencyKey: string, expected: array{amount: int, currency: string}} */
    public function toArray(): array
    {
        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'orderId' => $this->orderId,
            'idempotencyKey' => $this->idempotencyKey,
            'expected' => ['amount' => $this->amount, 'currency' => $this->currency->value],
        ];
    }
}
