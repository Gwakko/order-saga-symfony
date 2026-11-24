<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Http\Serializer;

use App\Ordering\Application\Query\ListCustomerOrders\ListCustomerOrdersOutput;
use App\Shared\Infrastructure\Http\Serializer\OutputNormalizer;

/**
 * @extends OutputNormalizer<ListCustomerOrdersOutput>
 */
final class ListCustomerOrdersOutputNormalizer extends OutputNormalizer
{
    protected function outputClass(): string
    {
        return ListCustomerOrdersOutput::class;
    }

    protected function shape(object $output): array
    {
        return [
            'items' => array_map($this->normalizer->normalize(...), $output->items),
            'nextCursor' => $output->nextCursor,
        ];
    }
}
