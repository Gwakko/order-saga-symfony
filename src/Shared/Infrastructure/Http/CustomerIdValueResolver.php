<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Domain\Identifier\CustomerId;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

/**
 * Controller arguments of type `CustomerId` come from the `X-Customer-Id` header (02_use_cases.md section 0).
 */
final readonly class CustomerIdValueResolver implements ValueResolverInterface
{
    /**
     * @return iterable<CustomerId>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if ($argument->getType() !== CustomerId::class) {
            return [];
        }

        return [CustomerIdHeader::fromRequest($request)];
    }
}
