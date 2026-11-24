<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Gateway;

use App\Shared\Infrastructure\Redis\RedisConnection;

/**
 * Breaker state in Redis, shared by every worker (00_overview.md section 7.4). Keys per circuit:
 * `cb:{name}:opened` (open time), `cb:{name}:streak` (consecutive failures), `cb:{name}:calls` (sorted set of the
 * window, `f:`/`s:` members), `cb:{name}:probe` (`SET NX PX`, the single half-open probe).
 */
final readonly class RedisCircuitStateStore implements CircuitStateStore
{
    private const RECORD = <<<'LUA'
        local now = tonumber(ARGV[1])
        local window = tonumber(ARGV[2])
        redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', now - window)
        redis.call('ZADD', KEYS[1], now, ARGV[4])
        redis.call('PEXPIRE', KEYS[1], window * 2)
        local streak = 0
        if ARGV[3] == '1' then
            streak = redis.call('INCR', KEYS[2])
        else
            redis.call('DEL', KEYS[2])
        end
        local failures = 0
        for _, member in ipairs(redis.call('ZRANGE', KEYS[1], 0, -1)) do
            if string.sub(member, 1, 1) == 'f' then failures = failures + 1 end
        end
        return {streak, redis.call('ZCARD', KEYS[1]), failures}
        LUA;

    public function __construct(private RedisConnection $connection) {}

    public function openedAt(string $circuit): ?int
    {
        return $this->run(function (\Redis $redis) use ($circuit): ?int {
            $value = $redis->get($this->key($circuit, 'opened'));

            return is_string($value) ? (int) $value : null;
        });
    }

    public function open(string $circuit, int $nowMs): void
    {
        $this->run(function (\Redis $redis) use ($circuit, $nowMs): void {
            $redis->multi();
            $redis->del($this->key($circuit, 'streak'), $this->key($circuit, 'calls'), $this->key($circuit, 'probe'));
            $redis->set($this->key($circuit, 'opened'), (string) $nowMs);
            $redis->exec();
        });
    }

    public function close(string $circuit): void
    {
        $this->run(function (\Redis $redis) use ($circuit): void {
            $redis->del(
                $this->key($circuit, 'opened'),
                $this->key($circuit, 'streak'),
                $this->key($circuit, 'calls'),
                $this->key($circuit, 'probe'),
            );
        });
    }

    public function recordCall(string $circuit, bool $failure, int $nowMs, int $windowMs): CircuitCallStats
    {
        return $this->run(function (\Redis $redis) use ($circuit, $failure, $nowMs, $windowMs): CircuitCallStats {
            $member = ($failure ? 'f:' : 's:') . bin2hex(random_bytes(8));
            $result = $redis->eval(
                self::RECORD,
                [$this->key($circuit, 'calls'), $this->key($circuit, 'streak'), (string) $nowMs, (string) $windowMs, $failure ? '1' : '0', $member],
                2,
            );

            if (!is_array($result) || count($result) !== 3) {
                throw new \RedisException('Unexpected reply of the breaker script.');
            }

            [$streak, $calls, $failures] = array_map(
                static fn(mixed $value): int => is_int($value) ? $value : throw new \RedisException('Unexpected reply of the breaker script.'),
                array_values($result),
            );

            return new CircuitCallStats($streak, $calls, $failures);
        });
    }

    public function tryAcquireProbe(string $circuit, int $nowMs, int $ttlMs): bool
    {
        return $this->run(fn(\Redis $redis): bool => $redis->set(
            $this->key($circuit, 'probe'),
            (string) $nowMs,
            ['nx', 'px' => max(1, $ttlMs)],
        ) === true);
    }

    /**
     * @template T
     *
     * @param \Closure(\Redis): T $operation
     *
     * @return T
     */
    private function run(\Closure $operation): mixed
    {
        try {
            return $operation($this->connection->client());
        } catch (\RedisException $e) {
            $this->connection->disconnect();

            throw new CircuitStateStoreUnavailable($e->getMessage(), 0, $e);
        }
    }

    private function key(string $circuit, string $part): string
    {
        return sprintf('cb:%s:%s', $circuit, $part);
    }
}
