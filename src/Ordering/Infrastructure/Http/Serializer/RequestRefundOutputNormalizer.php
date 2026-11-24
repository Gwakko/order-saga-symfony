<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Http\Serializer;

use App\Ordering\Application\Command\RequestRefund\RequestRefundOutput;
use App\Shared\Infrastructure\Http\Serializer\OutputNormalizer;

/**
 * @extends OutputNormalizer<RequestRefundOutput>
 */
final class RequestRefundOutputNormalizer extends OutputNormalizer
{
    protected function outputClass(): string
    {
        return RequestRefundOutput::class;
    }

    protected function shape(object $output): array
    {
        return ['orderId' => $output->orderId, 'status' => $output->status->value];
    }
}
