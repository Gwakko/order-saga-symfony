<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Contract\GatewayInspection;

/** What the gateway knows about the charge; the wire values are part of the contract. */
enum InspectionOutcome: string
{
    case Succeeded = 'succeeded';
    case Declined = 'declined';
    case NotFound = 'not_found';
}
