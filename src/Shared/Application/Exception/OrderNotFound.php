<?php

declare(strict_types=1);

namespace App\Shared\Application\Exception;

use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;
use App\Shared\Domain\Identifier\OrderId;

/**
 * Also raised for an order of another customer: the existence of foreign orders is not disclosed (EC-70).
 * Lives in Shared because Ordering and Payment ports both raise it.
 */
final class OrderNotFound extends DomainError
{
    public function __construct(public readonly OrderId $orderId)
    {
        parent::__construct(sprintf('Order %s not found.', $orderId->value));
    }

    public function code(): DomainErrorCode
    {
        return DomainErrorCode::OrderNotFound;
    }
}
