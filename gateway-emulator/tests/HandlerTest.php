<?php

declare(strict_types=1);

namespace GatewayEmulator\Tests;

require_once __DIR__ . '/../autoload.php';

use GatewayEmulator\Handler;
use GatewayEmulator\Request;
use GatewayEmulator\Response;
use GatewayEmulator\Store;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HandlerTest extends TestCase
{
    private Handler $handler;
    private int $now = 1_000_000;

    protected function setUp(): void
    {
        $this->handler = new Handler(new Store(':memory:'), fn(): int => $this->now);
    }

    public function testOkChargeIsIdempotent(): void
    {
        $first = $this->charge('k1', 1999, 'EUR');
        $second = $this->charge('k1', 1999, 'EUR');

        self::assertSame(200, $first->status);
        self::assertSame($first->body, $second->body);
        $data = $this->json($first);
        self::assertSame('succeeded', $data['status']);
        self::assertSame(1999, $data['amount']);
        self::assertSame('EUR', $data['currency']);
        self::assertStringStartsWith('ch_', $data['chargeId']);
        self::assertSame(['requests' => 2, 'executed' => 1], $this->stats()['charges']['k1']);
        self::assertSame(1, $this->stats()['totals']['charges']);
    }

    public function testFindChargeByIdempotencyKey(): void
    {
        $created = $this->json($this->charge('k1', 500, 'EUR'));
        $found = $this->handler->handle(new Request('GET', '/charges/k1', null, ''));

        self::assertSame(200, $found->status);
        self::assertSame($created, $this->json($found));
        self::assertSame(404, $this->handler->handle(new Request('GET', '/charges/missing', null, ''))->status);
    }

    public function testSameKeyWithDifferentRequestConflicts(): void
    {
        $this->charge('k1', 500, 'EUR');

        self::assertSame(409, $this->charge('k1', 501, 'EUR')->status);
        self::assertSame(1, $this->stats()['totals']['charges']);
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidChargePayloads')]
    public function testInvalidChargeRequestIsRejected(array $payload): void
    {
        $response = $this->handler->handle(new Request('POST', '/charges', 'k', json_encode($payload, JSON_THROW_ON_ERROR)));

        self::assertSame(422, $response->status);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidChargePayloads(): iterable
    {
        yield 'float amount' => [['amount' => 19.99, 'currency' => 'EUR']];
        yield 'string amount' => [['amount' => '100', 'currency' => 'EUR']];
        yield 'zero amount' => [['amount' => 0, 'currency' => 'EUR']];
        yield 'bad currency' => [['amount' => 100, 'currency' => 'eur']];
        yield 'empty' => [[]];
    }

    public function testChargeRequiresIdempotencyKey(): void
    {
        $response = $this->handler->handle(new Request('POST', '/charges', null, '{"amount":1,"currency":"EUR"}'));

        self::assertSame(400, $response->status);
    }

    public function testModeDecline(): void
    {
        $this->setMode('decline');
        $response = $this->charge('k1', 1000, 'EUR');

        self::assertSame(402, $response->status);
        self::assertSame('declined', $this->json($response)['status']);
        self::assertSame('declined', $this->json($this->handler->handle(new Request('GET', '/charges/k1', null, '')))['status']);
        self::assertSame(0, $this->stats()['totals']['charges']);
    }

    public function testModeDelayReturnsPauseAndStillCharges(): void
    {
        $this->setMode('delay:12000');
        $response = $this->charge('k1', 1000, 'EUR');

        self::assertSame(12000, $response->delayMs);
        self::assertSame(200, $response->status);
        self::assertSame(1, $this->stats()['totals']['charges']);
    }

    public function testModeHttp500DoesNotCharge(): void
    {
        $this->setMode('http500');

        self::assertSame(500, $this->charge('k1', 1000, 'EUR')->status);
        self::assertSame(500, $this->charge('k1', 1000, 'EUR')->status);
        self::assertSame(0, $this->stats()['totals']['charges']);
    }

    public function testModeHttp503FailsExactlyNTimesThenRecovers(): void
    {
        $this->setMode('http503:2');

        self::assertSame(503, $this->charge('k1', 1000, 'EUR')->status);
        self::assertSame(503, $this->charge('k1', 1000, 'EUR')->status);
        self::assertSame(0, $this->stats()['totals']['charges']);
        self::assertSame(200, $this->charge('k1', 1000, 'EUR')->status);
        self::assertSame('ok', $this->stats()['mode']);
        self::assertSame(1, $this->stats()['totals']['charges']);
    }

    public function testModeAcceptThen502ChargesButAnswers502(): void
    {
        $this->setMode('accept-then-502');
        $response = $this->charge('k1', 1000, 'EUR');

        self::assertSame(502, $response->status);
        self::assertSame(1, $this->stats()['totals']['charges']);
        self::assertSame('succeeded', $this->json($this->handler->handle(new Request('GET', '/charges/k1', null, '')))['status']);

        $this->setMode('ok');
        self::assertSame(200, $this->charge('k1', 1000, 'EUR')->status);
        self::assertSame(1, $this->stats()['totals']['charges']);
    }

    public function testModeTruncatedJsonReturnsInvalidJson(): void
    {
        $this->setMode('truncated-json');
        $response = $this->charge('k1', 1000, 'EUR');

        self::assertSame(200, $response->status);
        self::assertNull(json_decode($response->body));
        self::assertSame(1, $this->stats()['totals']['charges']);
    }

    public function testModeNoChargeIdOmitsChargeId(): void
    {
        $this->setMode('no-charge-id');
        $data = $this->json($this->charge('k1', 1000, 'EUR'));

        self::assertArrayNotHasKey('chargeId', $data);
        self::assertSame('succeeded', $data['status']);
        self::assertSame(1, $this->stats()['totals']['charges']);
    }

    public function testModeWrongCurrencyReturnsOtherCurrency(): void
    {
        $this->setMode('wrong-currency');

        self::assertSame('USD', $this->json($this->charge('k1', 1000, 'EUR'))['currency']);
        self::assertSame('EUR', $this->json($this->charge('k2', 1000, 'USD'))['currency']);
        self::assertSame('USD', $this->json($this->handler->handle(new Request('GET', '/charges/k1', null, '')))['currency']);
    }

    public function testModeWrongAmountReturnsOtherAmount(): void
    {
        $this->setMode('wrong-amount');

        self::assertSame(1001, $this->json($this->charge('k1', 1000, 'EUR'))['amount']);
        self::assertSame(1001, $this->json($this->handler->handle(new Request('GET', '/charges/k1', null, '')))['amount']);
    }

    public function testModeDropConnectionAbortsAfterPartialBody(): void
    {
        $this->setMode('drop-connection');
        $response = $this->charge('k1', 1000, 'EUR');

        self::assertTrue($response->abort);
        self::assertSame(1, $this->stats()['totals']['charges']);
    }

    public function testModeUnknownStatusReturnsWeird(): void
    {
        $this->setMode('unknown-status');

        self::assertSame('weird', $this->json($this->charge('k1', 1000, 'EUR'))['status']);
    }

    public function testModeLateSuccessIsPendingThenSucceeded(): void
    {
        $this->setMode('late-success:5000');

        self::assertSame('pending', $this->json($this->charge('k1', 1000, 'EUR'))['status']);
        self::assertSame('pending', $this->find('k1')['status']);

        $this->now += 4999;
        self::assertSame('pending', $this->find('k1')['status']);

        $this->now += 1;
        self::assertSame('succeeded', $this->find('k1')['status']);
        self::assertSame(1, $this->stats()['totals']['charges']);
    }

    public function testModeOkIsDefaultAndCanBeRestored(): void
    {
        self::assertSame('ok', $this->stats()['mode']);
        $this->setMode('http500');
        $this->setMode('ok');

        self::assertSame(200, $this->charge('k1', 1000, 'EUR')->status);
    }

    #[DataProvider('invalidModes')]
    public function testUnknownModeIsRejected(string $mode): void
    {
        $response = $this->handler->handle(new Request('POST', '/__admin/mode', null, json_encode(['mode' => $mode], JSON_THROW_ON_ERROR)));

        self::assertSame(422, $response->status);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidModes(): iterable
    {
        yield 'unknown' => ['explode'];
        yield 'delay without value' => ['delay'];
        yield 'delay not number' => ['delay:abc'];
        yield 'param on plain mode' => ['ok:1'];
        yield 'negative' => ['http503:-1'];
    }

    public function testRefundIsIdempotentByKey(): void
    {
        $chargeId = $this->json($this->charge('pay-1', 1000, 'EUR'))['chargeId'];

        $first = $this->refund('refund-pay-1', $chargeId, 1000);
        $second = $this->refund('refund-pay-1', $chargeId, 1000);

        self::assertSame(200, $first->status);
        self::assertSame($first->body, $second->body);
        self::assertSame('refunded', $this->json($first)['status']);
        self::assertSame(['requests' => 2, 'executed' => 1], $this->stats()['refunds']['refund-pay-1']);
        self::assertSame(1, $this->stats()['totals']['refunds']);
    }

    public function testRefundAfterTwo503ExecutesOnce(): void
    {
        $chargeId = $this->json($this->charge('pay-1', 1000, 'EUR'))['chargeId'];
        $this->setMode('http503:2');

        self::assertSame(503, $this->refund('refund-pay-1', $chargeId, 1000)->status);
        self::assertSame(503, $this->refund('refund-pay-1', $chargeId, 1000)->status);
        self::assertSame(200, $this->refund('refund-pay-1', $chargeId, 1000)->status);
        self::assertSame(1, $this->stats()['totals']['refunds']);
    }

    public function testRefundReplayAfterAcceptThen502ReturnsSameRefundId(): void
    {
        $chargeId = $this->json($this->charge('pay-1', 1000, 'EUR'))['chargeId'];
        $this->setMode('accept-then-502');
        self::assertSame(502, $this->refund('refund-pay-1', $chargeId, 1000)->status);

        $this->setMode('ok');
        $replay = $this->refund('refund-pay-1', $chargeId, 1000);

        self::assertSame(200, $replay->status);
        self::assertStringStartsWith('rf_', $this->json($replay)['refundId']);
        self::assertSame(1, $this->stats()['totals']['refunds']);
    }

    public function testRefundRejectsUnknownDeclinedAndExcessiveCharges(): void
    {
        $chargeId = $this->json($this->charge('pay-1', 1000, 'EUR'))['chargeId'];

        self::assertSame(404, $this->refund('r1', 'ch_missing', 1000)->status);
        self::assertSame(422, $this->refund('r2', $chargeId, 1001)->status);
        self::assertSame(409, $this->refundConflict($chargeId));

        $this->setMode('decline');
        $this->charge('pay-2', 1000, 'EUR');
        $declinedId = $this->json($this->handler->handle(new Request('GET', '/charges/pay-2', null, '')))['chargeId'];
        $this->setMode('ok');

        self::assertSame(422, $this->refund('r3', $declinedId, 1000)->status);
        self::assertSame(1, $this->stats()['totals']['refunds']);
    }

    public function testRefundOfPendingChargeIsRejectedUntilItSucceeds(): void
    {
        $this->setMode('late-success:1000');
        $chargeId = $this->json($this->charge('pay-1', 1000, 'EUR'))['chargeId'];
        $this->setMode('ok');

        self::assertSame(422, $this->refund('r1', $chargeId, 1000)->status);

        $this->now += 1000;
        self::assertSame(200, $this->refund('r1', $chargeId, 1000)->status);
    }

    public function testRefundModesShapeResponse(): void
    {
        $chargeId = $this->json($this->charge('pay-1', 1000, 'EUR'))['chargeId'];

        $this->setMode('no-charge-id');
        self::assertArrayNotHasKey('refundId', $this->json($this->refund('r1', $chargeId, 1000)));

        $this->setMode('truncated-json');
        self::assertNull(json_decode($this->refund('r1', $chargeId, 1000)->body));

        $this->setMode('drop-connection');
        self::assertTrue($this->refund('r1', $chargeId, 1000)->abort);

        $this->setMode('delay:300');
        self::assertSame(300, $this->refund('r1', $chargeId, 1000)->delayMs);

        $this->setMode('http500');
        self::assertSame(500, $this->refund('r2', $chargeId, 1000)->status);

        $this->setMode('unknown-status');
        self::assertSame('weird', $this->json($this->refund('r3', $chargeId, 500))['status']);
    }

    public function testResetClearsEverything(): void
    {
        $this->setMode('decline');
        $this->charge('k1', 1000, 'EUR');
        $this->handler->handle(new Request('POST', '/__admin/reset', null, ''));

        self::assertSame('ok', $this->stats()['mode']);
        self::assertSame([], $this->stats()['charges']);
    }

    public function testUnknownRouteAndMethod(): void
    {
        self::assertSame(404, $this->handler->handle(new Request('GET', '/nope', null, ''))->status);
        self::assertSame(405, $this->handler->handle(new Request('GET', '/charges', null, ''))->status);
    }

    private function charge(string $key, int $amount, string $currency): Response
    {
        return $this->handler->handle(new Request('POST', '/charges', $key, json_encode(['amount' => $amount, 'currency' => $currency], JSON_THROW_ON_ERROR)));
    }

    private function refund(string $key, string $chargeId, int $amount): Response
    {
        return $this->handler->handle(new Request('POST', '/refunds', $key, json_encode(['chargeId' => $chargeId, 'amount' => $amount, 'currency' => 'EUR'], JSON_THROW_ON_ERROR)));
    }

    private function refundConflict(string $chargeId): int
    {
        $this->refund('r-conflict', $chargeId, 400);

        return $this->refund('r-conflict', $chargeId, 300)->status;
    }

    private function setMode(string $mode): void
    {
        $response = $this->handler->handle(new Request('POST', '/__admin/mode', null, json_encode(['mode' => $mode], JSON_THROW_ON_ERROR)));
        self::assertSame(200, $response->status);
    }

    /** @return array<string, mixed> */
    private function find(string $key): array
    {
        return $this->json($this->handler->handle(new Request('GET', '/charges/' . $key, null, '')));
    }

    /** @return array<string, mixed> */
    private function stats(): array
    {
        return $this->json($this->handler->handle(new Request('GET', '/__admin/stats', null, '')));
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        $data = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
