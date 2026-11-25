<?php

declare(strict_types=1);

namespace App\Tests\Unit\Saga\Contract\GatewayInspection;

/** Reads contracts/inspect-gateway-charge/v1 (shared with the Go worker). */
final class GoldenFiles
{
    private const string DIR = __DIR__ . '/../../../../../contracts/inspect-gateway-charge/v1/';

    /** @return array<mixed> */
    public static function json(string $file): array
    {
        $decoded = json_decode(self::raw($file), true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : throw new \RuntimeException(sprintf('Golden file %s is not a JSON object.', $file));
    }

    public static function raw(string $file): string
    {
        $contents = file_get_contents(self::DIR . $file);

        return $contents !== false ? $contents : throw new \RuntimeException(sprintf('Golden file %s is missing.', $file));
    }
}
