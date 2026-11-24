<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Serializer;

use App\Shared\Infrastructure\Http\Problem\ProblemDetails;
use App\Shared\Infrastructure\Http\Problem\ProblemViolation;

/**
 * @extends OutputNormalizer<ProblemDetails>
 */
final class ProblemDetailsNormalizer extends OutputNormalizer
{
    protected function outputClass(): string
    {
        return ProblemDetails::class;
    }

    protected function shape(object $output): array
    {
        $body = ['type' => $output->type, 'title' => $output->title, 'status' => $output->status, 'code' => $output->code];

        if ($output->detail !== null) {
            $body['detail'] = $output->detail;
        }

        if ($output->errors !== []) {
            $body['errors'] = array_map(
                static fn(ProblemViolation $violation): array => ['propertyPath' => $violation->propertyPath, 'message' => $violation->message],
                $output->errors,
            );
        }

        if ($output->currentPrice !== null) {
            $body['currentPrice'] = $this->normalizer->normalize($output->currentPrice);
        }

        return $body;
    }
}
