<?php

declare(strict_types=1);

namespace App\Payment\Application\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;
use App\Shared\Domain\Identifier\OrderId;

final class PaymentDeclined extends DomainError
{
    public const string ABANDONED = 'abandoned';

    public function __construct(public readonly OrderId $orderId, public readonly string $reason)
    {
        parent::__construct(sprintf('Payment of order %s was declined: %s.', $orderId->value, $reason));
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::PaymentDeclined;
    }
}
