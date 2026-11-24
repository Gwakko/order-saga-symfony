<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\GraphQL;

use App\Inventory\Application\Query\GetProductAvailability\GetProductAvailabilityQuery;
use App\Inventory\Application\Query\GetProductAvailability\ProductAvailabilityOutput;
use App\Ordering\Application\Query\GetOrder\GetOrderQuery;
use App\Ordering\Application\Query\GetOrder\OrderDetailsOutput;
use App\Ordering\Application\Query\ListCustomerOrders\ListCustomerOrdersOutput;
use App\Ordering\Application\Query\ListCustomerOrders\ListCustomerOrdersQuery;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Infrastructure\Bus\QueryBus;

/**
 * Root fields. A resolver only turns arguments into a query and sends it to `query.bus`: no rules, no storage.
 */
final readonly class QueryResolver
{
    public function __construct(private QueryBus $queries) {}

    /**
     * @param array<string, mixed> $args
     */
    public function order(mixed $root, array $args, GraphQlContext $context): OrderDetailsOutput
    {
        // Same normalisation as the REST controller: ids are lower-case.
        return $this->queries->execute(
            new GetOrderQuery(strtolower(self::string($args, 'id')), $context->customerId->value),
            OrderDetailsOutput::class,
        );
    }

    /**
     * @param array<string, mixed> $args
     */
    public function myOrders(mixed $root, array $args, GraphQlContext $context): ListCustomerOrdersOutput
    {
        $status = $args['status'] ?? null;
        $limit = $args['limit'] ?? ListCustomerOrdersQuery::DEFAULT_LIMIT;
        $cursor = $args['cursor'] ?? null;

        return $this->queries->execute(
            new ListCustomerOrdersQuery(
                $context->customerId->value,
                is_string($status) ? OrderStatus::from(strtolower($status)) : null,
                is_int($limit) ? $limit : ListCustomerOrdersQuery::DEFAULT_LIMIT,
                is_string($cursor) ? $cursor : null,
            ),
            ListCustomerOrdersOutput::class,
        );
    }

    /**
     * @param array<string, mixed> $args
     */
    public function product(mixed $root, array $args, GraphQlContext $context): ProductAvailabilityOutput
    {
        return $this->queries->execute(
            new GetProductAvailabilityQuery(strtolower(self::string($args, 'id'))),
            ProductAvailabilityOutput::class,
        );
    }

    /**
     * @param array<string, mixed> $args
     */
    private static function string(array $args, string $name): string
    {
        $value = $args[$name] ?? null;

        return is_string($value) ? $value : throw new \UnexpectedValueException(sprintf('Argument "%s" is not a string.', $name));
    }
}
