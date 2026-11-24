<?php

declare(strict_types=1);

namespace App\Payment\Application\Port;

use App\Shared\Domain\Money;

/**
 * A gateway answer about a charge. `amount` is what the gateway says it charged, not what we asked for.
 */
final readonly class ChargeResult
{
    public function __construct(
        public string $chargeId,
        public GatewayChargeStatus $status,
        public Money $amount,
        public ?string $declineReason = null,
    ) {}
}
