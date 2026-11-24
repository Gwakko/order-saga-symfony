<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Http\Request;

use App\Ordering\Application\Query\ListCustomerOrders\ListCustomerOrdersQuery;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Domain\Identifier\CustomerId;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query string of `GET /api/orders` (02_use_cases.md section 4.2).
 */
final readonly class ListCustomerOrdersRequest
{
    public function __construct(
        #[Assert\Choice(callback: [self::class, 'statuses'])]
        public ?string $status = null,
        #[Assert\Range(min: 1, max: ListCustomerOrdersQuery::MAX_LIMIT)]
        public int $limit = ListCustomerOrdersQuery::DEFAULT_LIMIT,
        #[Assert\Length(max: 200)]
        public ?string $cursor = null,
    ) {}

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return array_map(static fn(OrderStatus $status): string => $status->value, OrderStatus::cases());
    }

    public function toQuery(CustomerId $customerId): ListCustomerOrdersQuery
    {
        return new ListCustomerOrdersQuery(
            $customerId->value,
            $this->status === null ? null : OrderStatus::from($this->status),
            $this->limit,
            $this->cursor,
        );
    }
}
