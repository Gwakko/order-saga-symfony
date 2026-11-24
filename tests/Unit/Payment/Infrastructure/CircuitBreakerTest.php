<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Infrastructure;

use App\Payment\Application\Exception\CircuitOpen;
use App\Payment\Application\Exception\PaymentGatewayUnavailable;
use App\Payment\Application\Exception\PaymentOutcomeUnknown;
use App\Payment\Infrastructure\Gateway\CircuitBreaker;
use App\Payment\Infrastructure\Gateway\CircuitBreakerConfig;
use App\Payment\Infrastructure\Gateway\CircuitBreakingPaymentGateway;
use App\Payment\Infrastructure\Gateway\CircuitState;
use App\Payment\Infrastructure\Gateway\GatewayRequestRejected;
use App\Payment\Infrastructure\Gateway\InMemoryCircuitStateStore;
use App\Payment\Application\Port\GatewayChargeStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tests\Support\Fake\FakePaymentGateway;
use App\Tests\Support\Fake\GatewayScenario;
use App\Tests\Support\Fake\RecordingLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class CircuitBreakerTest extends TestCase
{
    private MockClock $clock;
    private InMemoryCircuitStateStore $store;
    private RecordingLogger $logger;
    private CircuitBreaker $breaker;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-05-01 10:00:00 UTC');
        $this->store = new InMemoryCircuitStateStore();
        $this->logger = new RecordingLogger();
        $this->breaker = new CircuitBreaker('payment-gateway', $this->store, $this->clock, $this->logger);
    }

    #[Group('T-U-40')]
    public function testDeclineIsNotFailure(): void
    {
        $gateway = new FakePaymentGateway();
        $gateway->script(...array_fill(0, 12, GatewayScenario::Decline));
        $guarded = new CircuitBreakingPaymentGateway($gateway, $this->breaker);

        for ($i = 1; $i <= 12; ++$i) {
            $result = $guarded->charge('pay-' . $i, Money::positive(1999, Currency::EUR));
            self::assertSame(GatewayChargeStatus::Declined, $result->status);
        }

        self::assertSame(CircuitState::Closed, $this->breaker->state());
        self::assertSame(12, $gateway->chargeCalls);
    }

    #[Group('T-U-40')]
    public function testRejectedRequestIsNotFailure(): void
    {
        for ($i = 0; $i < 8; ++$i) {
            try {
                $this->breaker->call(self::fails(new GatewayRequestRejected('HTTP 422')));
                self::fail('Expected rejection.');
            } catch (GatewayRequestRejected) {
            }
        }

        self::assertSame(CircuitState::Closed, $this->breaker->state());
    }

    #[Group('T-U-41')]
    public function testOpensAfterThreshold(): void
    {
        $gateway = new FakePaymentGateway();
        $gateway->script(...array_fill(0, 5, GatewayScenario::Unavailable));
        $guarded = new CircuitBreakingPaymentGateway($gateway, $this->breaker);

        for ($i = 1; $i <= 4; ++$i) {
            $this->expectThrows(PaymentGatewayUnavailable::class, fn() => $guarded->charge('pay-' . $i, Money::positive(1999, Currency::EUR)));
            self::assertSame(CircuitState::Closed, $this->breaker->state(), 'four failures keep it closed');
        }

        $this->expectThrows(PaymentGatewayUnavailable::class, fn() => $guarded->charge('pay-5', Money::positive(1999, Currency::EUR)));
        self::assertSame(CircuitState::Open, $this->breaker->state());

        $this->expectThrows(CircuitOpen::class, fn() => $guarded->charge('pay-6', Money::positive(1999, Currency::EUR)));
        $this->expectThrows(CircuitOpen::class, fn() => $guarded->findCharge('pay-6'));
        $this->expectThrows(CircuitOpen::class, fn() => $guarded->refund('refund-pay-6', 'ch_1', Money::positive(1999, Currency::EUR)));

        self::assertSame(5, $gateway->chargeCalls, 'an open circuit does not reach the gateway');
        self::assertSame(0, $gateway->findCalls);
        self::assertSame(0, $gateway->refundCalls);
        self::assertTrue($this->logger->hasRecord('warning'));
    }

    #[Group('T-U-41')]
    public function testTimeoutsAndServerErrorsBothCountAndASuccessResetsTheStreak(): void
    {
        for ($i = 0; $i < 4; ++$i) {
            $this->expectFailure(self::fails(new PaymentOutcomeUnknown('timeout')));
        }

        self::assertSame('ok', $this->breaker->call(self::returns('ok')));

        for ($i = 0; $i < 4; ++$i) {
            $this->expectFailure(self::fails(new PaymentGatewayUnavailable('503')));
        }

        self::assertSame(CircuitState::Closed, $this->breaker->state(), 'the streak was reset by the success');

        $this->expectFailure(self::fails(new PaymentGatewayUnavailable('503')));
        self::assertSame(CircuitState::Open, $this->breaker->state());
    }

    #[Group('T-U-41')]
    public function testOpensOnFailureRateAtTenCalls(): void
    {
        // Alternating outcomes never build a streak of 5; 5 failures of 10 calls reach 50 %.
        for ($i = 1; $i <= 10; ++$i) {
            self::assertSame(CircuitState::Closed, $this->breaker->state());

            if ($i % 2 === 0) {
                $this->expectFailure(self::fails(new PaymentOutcomeUnknown('timeout')));
            } else {
                $this->breaker->call(self::returns('ok'));
            }
        }

        self::assertSame(CircuitState::Open, $this->breaker->state());
    }

    #[Group('T-U-41')]
    public function testFailuresOutsideTheWindowDoNotCountTowardsTheRate(): void
    {
        for ($i = 1; $i <= 8; ++$i) {
            if ($i % 2 === 0) {
                $this->expectFailure(self::fails(new PaymentOutcomeUnknown('timeout')));
            } else {
                $this->breaker->call(self::returns('ok'));
            }

            $this->clock->sleep(5);
        }

        // Eight calls spread over 40 s; at most 7 of them fit into a 30 s window: the rate rule cannot fire.
        for ($i = 0; $i < 2; ++$i) {
            $this->breaker->call(self::returns('ok'));
        }

        self::assertSame(CircuitState::Closed, $this->breaker->state());
    }

    #[Group('T-U-42')]
    public function testHalfOpenSingleProbe(): void
    {
        $this->trip();
        $this->clock->sleep(29);
        $this->expectCircuitOpen(self::returns('must not run'));
        self::assertSame(CircuitState::Open, $this->breaker->state());

        $this->clock->sleep(1);
        self::assertSame(CircuitState::HalfOpen, $this->breaker->state());

        $ran = 0;
        $parallelRejected = false;
        $result = $this->breaker->call(function () use (&$ran, &$parallelRejected): string {
            ++$ran;

            // A second caller during the probe is turned away.
            try {
                $this->breaker->call(self::returns('second probe'));
            } catch (CircuitOpen) {
                $parallelRejected = true;
            }

            return self::returns('probe ok')();
        });

        self::assertSame('probe ok', $result);
        self::assertSame(1, $ran);
        self::assertTrue($parallelRejected);
        self::assertSame(CircuitState::Closed, $this->breaker->state(), 'a successful probe closes the circuit');
        self::assertSame('again', $this->breaker->call(self::returns('again')));
    }

    #[Group('T-U-42')]
    public function testFailedProbeOpensAgainForThirtySeconds(): void
    {
        $this->trip();
        $this->clock->sleep(30);

        $this->expectFailure(self::fails(new PaymentGatewayUnavailable('503')));
        self::assertSame(CircuitState::Open, $this->breaker->state());

        $this->clock->sleep(29);
        $this->expectCircuitOpen(self::returns('must not run'));
        $this->clock->sleep(1);
        self::assertSame(CircuitState::HalfOpen, $this->breaker->state());
        self::assertSame('probe', $this->breaker->call(self::returns('probe')));
        self::assertSame(CircuitState::Closed, $this->breaker->state());
    }

    #[Group('T-U-42')]
    public function testDeclinedProbeStillClosesTheCircuit(): void
    {
        $this->trip();
        $this->clock->sleep(30);

        self::assertSame('declined result', $this->breaker->call(self::returns('declined result')));
        self::assertSame(CircuitState::Closed, $this->breaker->state());
    }

    #[Group('T-U-42')]
    public function testProbeThatNeverReportsFreesTheSlotAfterItsTtl(): void
    {
        $this->trip();
        $this->clock->sleep(30);
        $config = new CircuitBreakerConfig();

        // A crashed worker took the probe and never reported.
        self::assertTrue($this->store->tryAcquireProbe('payment-gateway', (int) $this->clock->now()->format('Uv'), $config->probeTtlSeconds * 1000));
        $this->expectCircuitOpen(self::returns('must not run'));

        $this->clock->sleep($config->probeTtlSeconds);
        self::assertSame('probe', $this->breaker->call(self::returns('probe')));
    }

    #[Group('T-U-43')]
    public function testStoreFailureDegradesToClosed(): void
    {
        $this->store->breakStore();
        $calls = 0;

        for ($i = 0; $i < 8; ++$i) {
            try {
                $this->breaker->call(static function () use (&$calls): string {
                    ++$calls;

                    return self::fails(new PaymentGatewayUnavailable('503'))();
                });
            } catch (PaymentGatewayUnavailable) {
            }
        }

        self::assertSame(8, $calls, 'every call reaches the gateway while the store is down');
        self::assertSame('ok', $this->breaker->call(self::returns('ok')));
        self::assertSame(CircuitState::Closed, $this->breaker->state());
        self::assertTrue($this->logger->hasRecord('warning'));
    }

    private function trip(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->expectFailure(self::fails(new PaymentOutcomeUnknown('timeout')));
        }

        self::assertSame(CircuitState::Open, $this->breaker->state());
    }

    /**
     * @param \Closure(): mixed $call
     */
    private function expectFailure(\Closure $call): void
    {
        try {
            $this->breaker->call($call);
        } catch (PaymentOutcomeUnknown|PaymentGatewayUnavailable) {
            return;
        }

        self::fail('Expected a gateway failure.');
    }

    /**
     * @param \Closure(): mixed $call
     */
    private function expectCircuitOpen(\Closure $call): void
    {
        try {
            $this->breaker->call($call);
        } catch (CircuitOpen) {
            return;
        }

        self::fail('Expected CircuitOpen.');
    }

    /**
     * @param class-string<\Throwable> $class
     */
    private function expectThrows(string $class, \Closure $call): void
    {
        try {
            $call();
        } catch (\Throwable $e) {
            self::assertInstanceOf($class, $e);

            return;
        }

        self::fail('Expected ' . $class);
    }

    /**
     * @return \Closure(): string
     */
    private static function fails(\Throwable $error): \Closure
    {
        return static function () use ($error): string {
            throw $error;
        };
    }

    /**
     * @return \Closure(): string
     */
    private static function returns(string $value): \Closure
    {
        return static fn(): string => $value;
    }
}
