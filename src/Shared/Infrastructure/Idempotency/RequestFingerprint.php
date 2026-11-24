<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Idempotency;

/**
 * `sha256(method + path + canonical JSON of the body)`: object keys are sorted, so key order and whitespace do not
 * matter; a body that is not JSON is hashed as it came.
 */
final class RequestFingerprint
{
    public static function of(string $method, string $path, string $body): string
    {
        return hash('sha256', strtoupper($method) . "\n" . $path . "\n" . self::canonicalBody($body));
    }

    private static function canonicalBody(string $body): string
    {
        try {
            $decoded = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $body;
        }

        return json_encode(self::sorted($decoded), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function sorted(mixed $value): mixed // mixed: arbitrary decoded JSON
    {
        if (is_array($value)) {
            return array_map(self::sorted(...), $value);
        }

        if ($value instanceof \stdClass) {
            $members = get_object_vars($value);
            ksort($members, SORT_STRING);

            return (object) array_map(self::sorted(...), $members);
        }

        return $value;
    }
}
