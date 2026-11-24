<?php

declare(strict_types=1);

namespace App\Payment\Domain;

/**
 * What differed between the request and the gateway's confirmed charge (EC-36, EC-37).
 */
enum MismatchKind: string
{
    case Currency = 'currency_mismatch';
    case Amount = 'amount_mismatch';
}
