<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Admin side of the real gateway emulator (`gateway-emulator/`): failure modes, statistics, reset.
 */
final readonly class GatewayEmulator
{
    private HttpClientInterface $http;

    public function __construct(public string $baseUri)
    {
        $this->http = HttpClient::create();
    }

    public static function fromEnvironment(): self
    {
        $url = $_SERVER['PAYMENT_GATEWAY_URL'] ?? $_ENV['PAYMENT_GATEWAY_URL'] ?? 'http://gateway-emulator:8081';

        return new self(is_string($url) ? $url : 'http://gateway-emulator:8081');
    }

    public function mode(string $mode): void
    {
        $this->http->request('POST', $this->baseUri . '/__admin/mode', ['json' => ['mode' => $mode]])->getContent();
    }

    public function reset(): void
    {
        $this->http->request('POST', $this->baseUri . '/__admin/reset')->getContent();
    }

    /**
     * @return array{charges: array<string, array{requests: int, executed: int}>, refunds: array<string, array{requests: int, executed: int}>, totals: array{charges: int, refunds: int}}
     */
    public function stats(): array
    {
        /** @var array{charges: array<string, array{requests: int, executed: int}>, refunds: array<string, array{requests: int, executed: int}>, totals: array{charges: int, refunds: int}} */
        return $this->http->request('GET', $this->baseUri . '/__admin/stats')->toArray();
    }
}
