<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Http\Serializer;

use App\Ordering\Application\Command\CancelOrder\CancelOrderOutput;
use App\Shared\Infrastructure\Http\Serializer\OutputNormalizer;

/**
 * @extends OutputNormalizer<CancelOrderOutput>
 */
final class CancelOrderOutputNormalizer extends OutputNormalizer
{
    protected function outputClass(): string
    {
        return CancelOrderOutput::class;
    }

    protected function shape(object $output): array
    {
        return [
            'orderId' => $output->orderId,
            'status' => $output->status->value,
            'cancellationRequested' => $output->cancellationRequested,
        ];
    }
}
