<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Contract\GatewayInspection;

/**
 * A request or an answer of `InspectGatewayCharge` that does not follow contracts/inspect-gateway-charge/v1.
 * The workflow treats a violation of the answer like any other failure of the inspection (pre-M9 behavior).
 */
final class InspectionContractViolation extends \DomainException
{
    public static function because(string $reason): self
    {
        return new self('InspectGatewayCharge contract violation: ' . $reason);
    }
}
