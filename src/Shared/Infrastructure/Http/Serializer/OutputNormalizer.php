<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Serializer;

use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Base of the normalizers that define the JSON shape of one Output DTO. The wire format is spelled out here, so a
 * rename of an application DTO property cannot silently change the API.
 *
 * @template T of object
 */
abstract class OutputNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @return class-string<T>
     */
    abstract protected function outputClass(): string;

    /**
     * @param T $output
     *
     * @return array<string, mixed>
     */
    abstract protected function shape(object $output): array;

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    final public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        $class = $this->outputClass();

        if (!$data instanceof $class) {
            throw new \InvalidArgumentException(sprintf('Expected %s, got %s.', $class, get_debug_type($data)));
        }

        return $this->shape($data);
    }

    /**
     * @param array<string, mixed> $context
     */
    final public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        $class = $this->outputClass();

        return $data instanceof $class;
    }

    /**
     * @return array<class-string, bool>
     */
    final public function getSupportedTypes(?string $format): array
    {
        return [$this->outputClass() => true];
    }
}
