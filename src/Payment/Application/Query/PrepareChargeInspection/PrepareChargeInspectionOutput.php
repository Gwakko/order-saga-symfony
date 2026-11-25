<?php

declare(strict_types=1);

namespace App\Payment\Application\Query\PrepareChargeInspection;

use App\Shared\Domain\Currency;

/**
 * What the saga needs to ask the gateway about a charge: the idempotency key (the payment id) and the expected money.
 * `inspect = false` carries no data.
 */
final readonly class PrepareChargeInspectionOutput
{
    private function __construct(
        public bool $inspect,
        public ?string $paymentId,
        public ?int $amount,
        public ?Currency $currency,
    ) {}

    public static function skip(): self
    {
        return new self(false, null, null, null);
    }

    public static function inspect(string $paymentId, int $amount, Currency $currency): self
    {
        return new self(true, $paymentId, $amount, $currency);
    }

    /** @return array{inspect: bool, paymentId: ?string, amount: ?int, currency: ?string} */
    public function toArray(): array
    {
        return [
            'inspect' => $this->inspect,
            'paymentId' => $this->paymentId,
            'amount' => $this->amount,
            'currency' => $this->currency?->value,
        ];
    }
}
