<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Redis;

/**
 * A phpredis client that connects on first use, so a Redis outage surfaces where the client is used (and can be
 * handled there) and not while the container is built.
 */
final class RedisConnection
{
    private ?\Redis $client = null;

    public function __construct(private readonly string $url, private readonly float $timeoutSeconds = 0.5) {}

    /**
     * @throws \RedisException when Redis cannot be reached
     */
    public function client(): \Redis
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $parts = parse_url($this->url);

        if ($parts === false || !isset($parts['host'])) {
            throw new \InvalidArgumentException(sprintf('Invalid Redis URL "%s".', $this->url));
        }

        $client = new \Redis();
        $client->connect($parts['host'], $parts['port'] ?? 6379, $this->timeoutSeconds, null, 0, $this->timeoutSeconds);
        $client->setOption(\Redis::OPT_READ_TIMEOUT, $this->timeoutSeconds);

        return $this->client = $client;
    }

    public function disconnect(): void
    {
        $this->client = null;
    }
}
