<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;
use App\Shared\Domain\Money;

final class PriceChanged extends DomainError
{
    public function __construct(public readonly Money $current)
    {
        parent::__construct(sprintf('Price changed, current price is %d %s.', $current->amount, $current->currency->value));
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::PriceChanged;
    }
}
