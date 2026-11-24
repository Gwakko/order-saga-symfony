<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Serializer;

use App\Shared\Application\Output\MoneyOutput;

/**
 * @extends OutputNormalizer<MoneyOutput>
 */
final class MoneyOutputNormalizer extends OutputNormalizer
{
    protected function outputClass(): string
    {
        return MoneyOutput::class;
    }

    protected function shape(object $output): array
    {
        return ['amount' => $output->amount, 'currency' => $output->currency];
    }
}
