<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Http\Serializer;

use App\Ordering\Application\Query\ListCustomerOrders\OrderSummaryOutput;
use App\Shared\Infrastructure\Http\ApiDateTime;
use App\Shared\Infrastructure\Http\Serializer\OutputNormalizer;

/**
 * @extends OutputNormalizer<OrderSummaryOutput>
 */
final class OrderSummaryOutputNormalizer extends OutputNormalizer
{
    protected function outputClass(): string
    {
        return OrderSummaryOutput::class;
    }

    protected function shape(object $output): array
    {
        return [
            'orderId' => $output->orderId,
            'status' => $output->status->value,
            'price' => $this->normalizer->normalize($output->price),
            'productId' => $output->productId,
            'createdAt' => ApiDateTime::format($output->createdAt),
            'updatedAt' => ApiDateTime::format($output->updatedAt),
        ];
    }
}
