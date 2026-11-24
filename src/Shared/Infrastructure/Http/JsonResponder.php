<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Turns an Output DTO into a JSON response through the Serializer (the normalizers decide the shape).
 */
final readonly class JsonResponder
{
    public function __construct(private SerializerInterface $serializer) {}

    /**
     * @param array<string, string> $headers
     */
    public function respond(object $output, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($this->serializer->serialize($output, 'json'), $status, $headers, true);
    }
}
