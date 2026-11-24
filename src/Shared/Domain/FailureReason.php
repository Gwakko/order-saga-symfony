<?php

declare(strict_types=1);

namespace App\Shared\Domain;

enum FailureReason: string
{
    case OutOfStock = 'OutOfStock';
    case PaymentDeclined = 'PaymentDeclined';
    case PaymentUnavailable = 'PaymentUnavailable';
    case ReservationExpired = 'ReservationExpired';
    case KeyRevoked = 'KeyRevoked';
    case CurrencyMismatch = 'CurrencyMismatch';
    case CancelledByCustomer = 'CancelledByCustomer';
    case CompensationFailed = 'CompensationFailed';
    /** A saga step failed for a non-business reason (infrastructure) after all retries; an operator decides (M6.4). */
    case SagaInterrupted = 'SagaInterrupted';
}
