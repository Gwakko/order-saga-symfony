<?php

declare(strict_types=1);

namespace App\Payment\Application\Port;

enum GatewayChargeStatus: string
{
    case Succeeded = 'succeeded';
    case Declined = 'declined';
}
