<?php

declare(strict_types=1);

namespace GatewayEmulator\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Сквозные проверки по настоящему HTTP против запущенного контейнера (`docker compose up -d gateway-emulator`).
 * Адрес: GATEWAY_EMULATOR_URL, иначе gateway-emulator:8081 (из контейнера), иначе 127.0.0.1:8081 (с хоста).
 */
final class HttpTest extends TestCase
{
    private string $host = '127.0.0.1';
    private int $port = 8081;

    protected function setUp(): void
    {
        [$this->host, $this->port] = $this->resolveEndpoint();
        self::assertSame(200, $this->call('GET', '/health')['status'], 'gateway-emulator is not reachable at ' . $this->host . ':' . $this->port);
        $this->call('POST', '/__admin/reset');
    }

    protected function tearDown(): void
    {
        $this->call('POST', '/__admin/reset');
    }

    public function testOkAndIdempotency(): void
    {
        $first = $this->charge('http-1');
        $second = $this->charge('http-1');

        self::assertSame(200, $first['status']);
        self::assertSame($first['body'], $second['body']);
        self::assertSame(1, $this->stats()['totals']['charges']);
        self::assertSame($first['body'], $this->call('GET', '/charges/http-1')['body']);
    }

    public function testDecline(): void
    {
        $this->mode('decline');

        self::assertSame(402, $this->charge('http-1')['status']);
    }

    public function testDelay(): void
    {
        $this->mode('delay:400');
        $start = microtime(true);
        $response = $this->charge('http-1');

        self::assertGreaterThanOrEqual(0.38, microtime(true) - $start);
        self::assertSame(200, $response['status']);
    }

    public function testHttp500(): void
    {
        $this->mode('http500');

        self::assertSame(500, $this->charge('http-1')['status']);
    }

    public function testHttp503Counter(): void
    {
        $this->mode('http503:2');

        self::assertSame([503, 503, 200], [$this->charge('http-1')['status'], $this->charge('http-1')['status'], $this->charge('http-1')['status']]);
        self::assertSame(1, $this->stats()['totals']['charges']);
    }

    public function testAcceptThen502(): void
    {
        $this->mode('accept-then-502');

        self::assertSame(502, $this->charge('http-1')['status']);
        self::assertSame('succeeded', $this->json($this->call('GET', '/charges/http-1')['body'])['status']);
    }

    public function testTruncatedJson(): void
    {
        $this->mode('truncated-json');
        $response = $this->charge('http-1');

        self::assertSame(200, $response['status']);
        self::assertTrue($response['complete']);
        self::assertNull(json_decode($response['body']));
    }

    public function testNoChargeId(): void
    {
        $this->mode('no-charge-id');

        self::assertArrayNotHasKey('chargeId', $this->json($this->charge('http-1')['body']));
    }

    public function testWrongCurrency(): void
    {
        $this->mode('wrong-currency');

        self::assertSame('USD', $this->json($this->charge('http-1')['body'])['currency']);
    }

    public function testWrongAmount(): void
    {
        $this->mode('wrong-amount');

        self::assertSame(1001, $this->json($this->charge('http-1')['body'])['amount']);
    }

    public function testDropConnectionCutsBodyShortOfContentLength(): void
    {
        $this->mode('drop-connection');
        $response = $this->charge('http-1');

        self::assertSame(200, $response['status']);
        self::assertFalse($response['complete'], 'body must be shorter than declared Content-Length');
        self::assertSame(1, $this->stats()['totals']['charges']);
    }

    public function testUnknownStatus(): void
    {
        $this->mode('unknown-status');

        self::assertSame('weird', $this->json($this->charge('http-1')['body'])['status']);
    }

    public function testLateSuccess(): void
    {
        $this->mode('late-success:600');

        self::assertSame('pending', $this->json($this->charge('http-1')['body'])['status']);
        self::assertSame('pending', $this->json($this->call('GET', '/charges/http-1')['body'])['status']);

        usleep(700_000);
        self::assertSame('succeeded', $this->json($this->call('GET', '/charges/http-1')['body'])['status']);
    }

    public function testRefundIsIdempotent(): void
    {
        $chargeId = $this->json($this->charge('http-1')['body'])['chargeId'];
        $payload = json_encode(['chargeId' => $chargeId, 'amount' => 1000, 'currency' => 'EUR'], JSON_THROW_ON_ERROR);

        $first = $this->call('POST', '/refunds', $payload, 'refund-http-1');
        $second = $this->call('POST', '/refunds', $payload, 'refund-http-1');

        self::assertSame(200, $first['status']);
        self::assertSame($first['body'], $second['body']);
        self::assertSame(1, $this->stats()['totals']['refunds']);
    }

    public function testParallelRequestsWithSameKeyChargeOnce(): void
    {
        $this->mode('delay:300');
        $sockets = [];

        for ($i = 0; $i < 4; $i++) {
            $sockets[] = $this->send('POST', '/charges', '{"amount":1000,"currency":"EUR"}', 'http-par');
        }

        foreach ($sockets as $socket) {
            self::assertSame(200, $this->read($socket)['status']);
        }

        self::assertSame(['requests' => 4, 'executed' => 1], $this->stats()['charges']['http-par']);
    }

    /** @return array{status: int, body: string, complete: bool} */
    private function charge(string $key): array
    {
        return $this->call('POST', '/charges', '{"amount":1000,"currency":"EUR"}', $key);
    }

    private function mode(string $mode): void
    {
        self::assertSame(200, $this->call('POST', '/__admin/mode', json_encode(['mode' => $mode], JSON_THROW_ON_ERROR))['status']);
    }

    /** @return array<string, mixed> */
    private function stats(): array
    {
        return $this->json($this->call('GET', '/__admin/stats')['body']);
    }

    /** @return array<string, mixed> */
    private function json(string $body): array
    {
        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /** @return array{status: int, body: string, complete: bool} */
    private function call(string $method, string $path, string $body = '', ?string $key = null): array
    {
        return $this->read($this->send($method, $path, $body, $key));
    }

    /** @return resource */
    private function send(string $method, string $path, string $body, ?string $key)
    {
        $socket = stream_socket_client('tcp://' . $this->host . ':' . $this->port, $errno, $error, 3);
        self::assertNotFalse($socket, 'connect failed: ' . $error);
        stream_set_timeout($socket, 15);
        $request = $method . ' ' . $path . " HTTP/1.1\r\nHost: gateway-emulator\r\nConnection: close\r\nContent-Type: application/json\r\n"
            . ($key !== null ? 'Idempotency-Key: ' . $key . "\r\n" : '')
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body;
        fwrite($socket, $request);

        return $socket;
    }

    /**
     * @param resource $socket
     * @return array{status: int, body: string, complete: bool}
     */
    private function read($socket): array
    {
        $raw = stream_get_contents($socket);
        fclose($socket);
        self::assertIsString($raw);
        [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        self::assertSame(1, preg_match('#^HTTP/1\.[01] (\d{3})#', $head, $status), 'bad response: ' . $raw);
        $declared = preg_match('/^Content-Length: (\d+)/mi', $head, $length) === 1 ? (int) $length[1] : strlen($body);

        return ['status' => (int) $status[1], 'body' => $body, 'complete' => strlen($body) >= $declared];
    }

    /** @return array{string, int} */
    private function resolveEndpoint(): array
    {
        $url = getenv('GATEWAY_EMULATOR_URL');

        if (is_string($url) && $url !== '') {
            return [(string) parse_url($url, PHP_URL_HOST), (int) (parse_url($url, PHP_URL_PORT) ?: 80)];
        }

        return gethostbyname('gateway-emulator') !== 'gateway-emulator' ? ['gateway-emulator', 8081] : ['127.0.0.1', 8081];
    }
}
