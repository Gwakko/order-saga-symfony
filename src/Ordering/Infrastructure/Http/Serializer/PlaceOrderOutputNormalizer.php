<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Http\Serializer;

use App\Ordering\Application\Command\PlaceOrder\PlaceOrderOutput;
use App\Shared\Infrastructure\Http\ApiDateTime;
use App\Shared\Infrastructure\Http\Serializer\OutputNormalizer;

/**
 * @extends OutputNormalizer<PlaceOrderOutput>
 */
final class PlaceOrderOutputNormalizer extends OutputNormalizer
{
    protected function outputClass(): string
    {
        return PlaceOrderOutput::class;
    }

    protected function shape(object $output): array
    {
        return [
            'orderId' => $output->orderId,
            'status' => $output->status->value,
            'price' => $this->normalizer->normalize($output->price),
            'createdAt' => ApiDateTime::format($output->createdAt),
        ];
    }
}
