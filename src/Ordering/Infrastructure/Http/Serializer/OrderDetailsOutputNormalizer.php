<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Http\Serializer;

use App\Ordering\Application\Query\GetOrder\OrderDetailsOutput;
use App\Shared\Infrastructure\Http\ApiDateTime;
use App\Shared\Infrastructure\Http\Serializer\OutputNormalizer;

/**
 * @extends OutputNormalizer<OrderDetailsOutput>
 */
final class OrderDetailsOutputNormalizer extends OutputNormalizer
{
    protected function outputClass(): string
    {
        return OrderDetailsOutput::class;
    }

    protected function shape(object $output): array
    {
        return [
            'orderId' => $output->orderId,
            'status' => $output->status->value,
            'price' => $this->normalizer->normalize($output->price),
            'productId' => $output->productId,
            'failureReason' => $output->failureReason,
            'cancellationRequested' => $output->cancellationRequested,
            'keyCode' => $output->keyCode,
            'createdAt' => ApiDateTime::format($output->createdAt),
            'updatedAt' => ApiDateTime::format($output->updatedAt),
            'completedAt' => ApiDateTime::formatNullable($output->completedAt),
        ];
    }
}
