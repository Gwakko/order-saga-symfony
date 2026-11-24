<?php

declare(strict_types=1);

namespace App\Payment\Application\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;
use App\Shared\Domain\Money;

/**
 * The gateway charged another amount than requested (EC-37). The payment is already `RefundPending`.
 */
final class AmountMismatch extends DomainError
{
    public function __construct(public readonly Money $expected, public readonly Money $charged)
    {
        parent::__construct(sprintf('Gateway charged %d, expected %d.', $charged->amount, $expected->amount));
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::AmountMismatch;
    }
}
