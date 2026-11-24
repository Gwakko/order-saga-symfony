<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\ListCustomerOrders;

use App\Ordering\Domain\OrderStatus;
use App\Shared\Application\Exception\InvalidInput;

final readonly class ListCustomerOrdersQuery
{
    public const int DEFAULT_LIMIT = 20;
    public const int MAX_LIMIT = 100;

    public function __construct(
        public string $customerId,
        public ?OrderStatus $status = null,
        public int $limit = self::DEFAULT_LIMIT,
        public ?string $cursor = null,
    ) {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidInput('limit', sprintf('must be 1..%d, got %d', self::MAX_LIMIT, $limit));
        }
    }
}
