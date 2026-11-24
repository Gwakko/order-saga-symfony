<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\GraphQL;

use App\Shared\Domain\Identifier\CustomerId;

/**
 * Per-request state handed to every resolver: who is asking and the request-scoped batch loader.
 */
final readonly class GraphQlContext
{
    public function __construct(public CustomerId $customerId, public ProductBatchLoader $products) {}
}
