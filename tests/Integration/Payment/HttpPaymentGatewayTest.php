<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment;

use App\Payment\Application\Exception\PaymentGatewayUnavailable;
use App\Payment\Application\Exception\PaymentOutcomeUnknown;
use App\Payment\Application\Port\GatewayChargeStatus;
use App\Payment\Infrastructure\Gateway\GatewayRequestRejected;
use App\Payment\Infrastructure\Gateway\HttpPaymentGateway;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tests\Integration\Support\GatewayEmulator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The HTTP adapter against the real gateway emulator and its failure modes (EC-24..EC-26, EC-29, EC-30, EC-36, EC-37).
 */
#[Group('M4.5')]
final class HttpPaymentGatewayTest extends TestCase
{
    private GatewayEmulator $emulator;
    private HttpPaymentGateway $gateway;

    protected function setUp(): void
    {
        $this->emulator = GatewayEmulator::fromEnvironment();
        $this->emulator->reset();
        $this->gateway = new HttpPaymentGateway(HttpClient::create(), $this->emulator->baseUri);
    }

    protected function tearDown(): void
    {
        $this->emulator->reset();
    }

    public function testChargeSucceedsAndIsIdempotentByKey(): void
    {
        $first = $this->gateway->charge('pay-1', $this->money(1999));
        $again = $this->gateway->charge('pay-1', $this->money(1999));

        self::assertSame(GatewayChargeStatus::Succeeded, $first->status);
        self::assertSame(1999, $first->amount->amount);
        self::assertSame(Currency::EUR, $first->amount->currency);
        self::assertSame($first->chargeId, $again->chargeId);
        self::assertSame(['requests' => 2, 'executed' => 1], $this->emulator->stats()['charges']['pay-1'], 'the idempotency key reached the gateway');
    }

    #[Group('T-I-17')]
    public function testTimeoutRaisesOutcomeUnknown(): void
    {
        $this->emulator->mode('delay:12000');
        $clock = new NativeClock();
        $started = (float) $clock->now()->format('U.u');

        try {
            $this->gateway->charge('pay-timeout', $this->money(1999));
            self::fail('Expected PaymentOutcomeUnknown.');
        } catch (PaymentOutcomeUnknown $e) {
            self::assertInstanceOf(\Throwable::class, $e->getPrevious());
        }

        $elapsed = (float) $clock->now()->format('U.u') - $started;
        self::assertGreaterThan(9.5, $elapsed, 'the HTTP timeout is 10 s');
        self::assertLessThan(11.5, $elapsed, 'the adapter gives up at the timeout, not when the gateway answers');
    }

    #[Group('T-I-17')]
    public function testShortTimeoutIsConfigurable(): void
    {
        $this->emulator->mode('delay:2000');
        $gateway = new HttpPaymentGateway(HttpClient::create(), $this->emulator->baseUri, 0.5);

        $this->expectException(PaymentOutcomeUnknown::class);
        $gateway->charge('pay-short', $this->money(1999));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenAnswers(): iterable
    {
        yield 'truncated JSON' => ['truncated-json'];
        yield 'no chargeId' => ['no-charge-id'];
        yield 'connection dropped after the headers' => ['drop-connection'];
        yield 'unknown status' => ['unknown-status'];
        yield 'pending status' => ['late-success:60000'];
        yield 'HTTP 500' => ['http500'];
        yield 'HTTP 503' => ['http503:5'];
    }

    #[Group('T-I-18')]
    #[DataProvider('brokenAnswers')]
    public function testMalformedResponses(string $mode): void
    {
        $this->emulator->mode($mode);

        try {
            $this->gateway->charge('pay-broken', $this->money(1999));
            self::fail('Expected PaymentOutcomeUnknown.');
        } catch (PaymentOutcomeUnknown) {
            self::addToAssertionCount(1);
        }
    }

    /**
     * @return iterable<string, array{MockResponse}>
     */
    public static function unreadableMockedAnswers(): iterable
    {
        $json = '{"chargeId":"ch_1","status":"succeeded","amount":1999,"currency":"EUR"}';
        $headers = ['response_headers' => ['Content-Type: application/json']];

        yield 'valid JSON but Content-Type is not JSON' => [new MockResponse($json, ['response_headers' => ['Content-Type: text/html']])];
        yield 'empty body' => [new MockResponse('', $headers)];
        yield 'JSON list instead of object' => [new MockResponse('[1,2]', $headers)];
        yield 'amount as string' => [new MockResponse('{"chargeId":"ch_1","status":"succeeded","amount":"1999","currency":"EUR"}', $headers)];
        yield 'amount as float' => [new MockResponse('{"chargeId":"ch_1","status":"succeeded","amount":19.99,"currency":"EUR"}', $headers)];
        yield 'unknown currency' => [new MockResponse('{"chargeId":"ch_1","status":"succeeded","amount":1999,"currency":"XXX"}', $headers)];
        yield 'empty chargeId' => [new MockResponse('{"chargeId":"","status":"succeeded","amount":1999,"currency":"EUR"}', $headers)];
        yield 'declined status with HTTP 200' => [new MockResponse('{"chargeId":"ch_1","status":"declined","amount":1999,"currency":"EUR"}', $headers)];
        yield 'succeeded status with HTTP 402' => [new MockResponse($json, $headers + ['http_code' => 402])];
        yield 'HTTP 502' => [new MockResponse('{"error":"bad_gateway"}', $headers + ['http_code' => 502])];
        yield 'transport error' => [new MockResponse('', ['error' => 'connection reset'])];
    }

    #[Group('T-I-18')]
    #[DataProvider('unreadableMockedAnswers')]
    public function testUnreadableAnswersAreNeverRefusals(MockResponse $response): void
    {
        $gateway = new HttpPaymentGateway(new MockHttpClient($response), 'http://gateway.test');

        $this->expectException(PaymentOutcomeUnknown::class);
        $gateway->charge('pay-mock', $this->money(1999));
    }

    #[Group('T-I-19')]
    public function testAcceptThen502ThenFindCharge(): void
    {
        $this->emulator->mode('accept-then-502');

        try {
            $this->gateway->charge('pay-502', $this->money(1999));
            self::fail('Expected PaymentOutcomeUnknown.');
        } catch (PaymentOutcomeUnknown) {
            self::addToAssertionCount(1);
        }

        $this->emulator->mode('ok');
        $found = $this->gateway->findCharge('pay-502');

        self::assertNotNull($found);
        self::assertSame(GatewayChargeStatus::Succeeded, $found->status);
        self::assertSame(1999, $found->amount->amount);

        $retry = $this->gateway->charge('pay-502', $this->money(1999));
        self::assertSame($found->chargeId, $retry->chargeId);
        self::assertSame(1, $this->emulator->stats()['charges']['pay-502']['executed'], 'one booking, no double charge');
    }

    public function testBankDeclineIsAResultNotAnException(): void
    {
        $this->emulator->mode('decline');

        $result = $this->gateway->charge('pay-declined', $this->money(1999));

        self::assertSame(GatewayChargeStatus::Declined, $result->status);
        self::assertSame('insufficient_funds', $result->declineReason);
        self::assertSame(0, $this->emulator->stats()['charges']['pay-declined']['executed']);
    }

    public function testReportedCurrencyAndAmountAreWhatTheGatewayBooked(): void
    {
        $this->emulator->mode('wrong-currency');
        $currency = $this->gateway->charge('pay-cur', $this->money(1999));
        self::assertSame(Currency::USD, $currency->amount->currency);

        $this->emulator->mode('wrong-amount');
        $amount = $this->gateway->charge('pay-amt', $this->money(1999));
        self::assertSame(2000, $amount->amount->amount);
    }

    public function testFindChargeReturnsNullForAnUnknownKey(): void
    {
        self::assertNull($this->gateway->findCharge('pay-never-sent'));
    }

    public function testFindChargeIsNotAffectedByChargeFailureModes(): void
    {
        $this->emulator->mode('ok');
        $this->gateway->charge('pay-find', $this->money(1999));
        $this->emulator->mode('http500');

        $found = $this->gateway->findCharge('pay-find');

        self::assertNotNull($found);
        self::assertSame(GatewayChargeStatus::Succeeded, $found->status);
    }

    public function testPendingChargeLookupIsUnavailableNotAnAnswer(): void
    {
        $this->emulator->mode('late-success:60000');

        try {
            $this->gateway->charge('pay-pending-find', $this->money(1999));
        } catch (PaymentOutcomeUnknown) {
            self::addToAssertionCount(1);
        }

        $this->emulator->mode('ok');
        $this->expectException(PaymentGatewayUnavailable::class);
        $this->gateway->findCharge('pay-pending-find');
    }

    public function testRefundIsIdempotentByKey(): void
    {
        $charge = $this->gateway->charge('pay-r', $this->money(1999));

        $first = $this->gateway->refund('refund-pay-r', $charge->chargeId, $this->money(1999));
        $again = $this->gateway->refund('refund-pay-r', $charge->chargeId, $this->money(1999));

        self::assertSame($first->refundId, $again->refundId);
        self::assertSame(1999, $first->amount->amount);
        self::assertSame(1, $this->emulator->stats()['refunds']['refund-pay-r']['executed']);
    }

    public function testRefundFailuresAreRetryable(): void
    {
        $charge = $this->gateway->charge('pay-rf', $this->money(1999));

        foreach (['http500', 'accept-then-502', 'truncated-json', 'no-charge-id', 'drop-connection', 'unknown-status'] as $mode) {
            $this->emulator->mode($mode);

            try {
                $this->gateway->refund('refund-pay-rf-' . $mode, $charge->chargeId, $this->money(1999));
                self::fail('Expected PaymentGatewayUnavailable for ' . $mode);
            } catch (PaymentGatewayUnavailable) {
                self::addToAssertionCount(1);
            }
        }

        $this->emulator->mode('ok');
        $refund = $this->gateway->refund('refund-pay-rf-accept-then-502', $charge->chargeId, $this->money(1999));
        self::assertNotSame('', $refund->refundId);
        self::assertSame(1, $this->emulator->stats()['refunds']['refund-pay-rf-accept-then-502']['executed'], 'the retry after a lost answer never refunds twice');
    }

    public function testRefundOfAnUnknownChargeIsRejectedNotRetried(): void
    {
        $this->expectException(GatewayRequestRejected::class);
        $this->gateway->refund('refund-nobody', 'ch_does_not_exist', $this->money(1999));
    }

    public function testIdempotencyKeyIsAlwaysSent(): void
    {
        /** @var list<array{string, string, array<array-key, mixed>}> $requests */
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options];

            return new MockResponse('{"error":"charge_not_found"}', ['http_code' => 404, 'response_headers' => ['Content-Type: application/json']]);
        });
        $gateway = new HttpPaymentGateway($client, 'http://gateway.test/');

        $gateway->findCharge('pay-key');

        try {
            $gateway->refund('refund-pay-key', 'ch_1', $this->money(100));
        } catch (GatewayRequestRejected) {
        }

        try {
            $gateway->charge('pay-key', $this->money(100));
        } catch (GatewayRequestRejected) {
        }

        self::assertCount(3, $requests);
        $keys = array_map(static fn(array $r): string => implode(',', array_filter(
            is_array($r[2]['headers'] ?? null) ? $r[2]['headers'] : [],
            static fn(mixed $h): bool => is_string($h) && str_starts_with(strtolower($h), 'idempotency-key'),
        )), $requests);
        self::assertSame(['Idempotency-Key: pay-key', 'Idempotency-Key: refund-pay-key', 'Idempotency-Key: pay-key'], $keys);
        self::assertSame('http://gateway.test/charges/pay-key', $requests[0][1]);
    }

    public function testClientErrorIsARejectionNotAFailure(): void
    {
        $client = new MockHttpClient(new MockResponse('{"error":"invalid_request"}', ['http_code' => 422, 'response_headers' => ['Content-Type: application/json']]));
        $gateway = new HttpPaymentGateway($client, 'http://gateway.test');

        $this->expectException(GatewayRequestRejected::class);
        $gateway->charge('pay-422', $this->money(100));
    }

    private function money(int $amount): Money
    {
        return Money::positive($amount, Currency::EUR);
    }
}
