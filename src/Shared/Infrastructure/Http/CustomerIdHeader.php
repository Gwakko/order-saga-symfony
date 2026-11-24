<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Domain\Exception\InvalidIdentifier;
use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Infrastructure\Http\Exception\CustomerRequired;
use Symfony\Component\HttpFoundation\Request;

/**
 * The only place that reads the caller's identity from a request. Both the argument resolver and the idempotency
 * listener (which runs before arguments are resolved) go through it.
 */
final class CustomerIdHeader
{
    public const string NAME = 'X-Customer-Id';

    public static function fromRequest(Request $request): CustomerId
    {
        $value = $request->headers->get(self::NAME);

        if ($value === null) {
            throw new CustomerRequired();
        }

        try {
            return CustomerId::fromString(strtolower(trim($value)));
        } catch (InvalidIdentifier) {
            throw new CustomerRequired();
        }
    }
}
