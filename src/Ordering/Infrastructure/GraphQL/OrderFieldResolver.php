<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\GraphQL;

use App\Ordering\Application\Query\GetOrder\OrderDetailsOutput;
use App\Ordering\Application\Query\ListCustomerOrders\OrderSummaryOutput;
use App\Shared\Infrastructure\Http\ApiDateTime;
use GraphQL\Deferred;

/**
 * Fields of `Order` and `OrderSummary` whose wire form differs from the Output DTO property: enum and timestamps in the
 * REST format, and `product` through the request-scoped batch loader. The other fields use webonyx's property reader.
 */
final readonly class OrderFieldResolver
{
    public function status(OrderDetailsOutput|OrderSummaryOutput $order): string
    {
        return strtoupper($order->status->value);
    }

    public function createdAt(OrderDetailsOutput|OrderSummaryOutput $order): string
    {
        return ApiDateTime::format($order->createdAt);
    }

    public function updatedAt(OrderDetailsOutput|OrderSummaryOutput $order): string
    {
        return ApiDateTime::format($order->updatedAt);
    }

    public function completedAt(OrderDetailsOutput $order): ?string
    {
        return ApiDateTime::formatNullable($order->completedAt);
    }

    public function product(OrderDetailsOutput|OrderSummaryOutput $order, mixed $args, GraphQlContext $context): Deferred
    {
        return $context->products->load($order->productId);
    }
}
