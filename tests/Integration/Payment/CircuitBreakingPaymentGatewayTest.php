<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment;

use App\Payment\Application\Exception\CircuitOpen;
use App\Payment\Application\Exception\PaymentOutcomeUnknown;
use App\Payment\Application\Port\GatewayChargeStatus;
use App\Payment\Infrastructure\Gateway\CircuitBreaker;
use App\Payment\Infrastructure\Gateway\CircuitBreakingPaymentGateway;
use App\Payment\Infrastructure\Gateway\CircuitState;
use App\Payment\Infrastructure\Gateway\HttpPaymentGateway;
use App\Payment\Infrastructure\Gateway\InMemoryCircuitStateStore;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tests\Integration\Support\GatewayEmulator;
use App\Tests\Support\Fake\RecordingLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\HttpClient;

/**
 * The breaker in front of the real emulator: EC-31 (no network calls while open) and EC-33 (recovery by a probe).
 */
#[Group('M4.5')]
final class CircuitBreakingPaymentGatewayTest extends TestCase
{
    private GatewayEmulator $emulator;
    private MockClock $clock;
    private CircuitBreaker $breaker;
    private CircuitBreakingPaymentGateway $gateway;

    protected function setUp(): void
    {
        $this->emulator = GatewayEmulator::fromEnvironment();
        $this->emulator->reset();
        $this->clock = new MockClock('2026-05-01 10:00:00 UTC');
        $this->breaker = new CircuitBreaker('payment-gateway', new InMemoryCircuitStateStore(), $this->clock, new RecordingLogger());
        $this->gateway = new CircuitBreakingPaymentGateway(new HttpPaymentGateway(HttpClient::create(), $this->emulator->baseUri), $this->breaker);
    }

    protected function tearDown(): void
    {
        $this->emulator->reset();
    }

    #[Group('T-U-41')]
    public function testOpenCircuitSendsNothingToTheGatewayAndRecoversThroughOneProbe(): void
    {
        $this->emulator->mode('http500');

        for ($i = 1; $i <= 5; ++$i) {
            try {
                $this->gateway->charge('pay-' . $i, $this->money());
                self::fail('Expected PaymentOutcomeUnknown.');
            } catch (PaymentOutcomeUnknown) {
                self::addToAssertionCount(1);
            }
        }

        self::assertSame(CircuitState::Open, $this->breaker->state());
        $requestsBefore = count($this->emulator->stats()['charges']);

        try {
            $this->gateway->charge('pay-6', $this->money());
            self::fail('Expected CircuitOpen.');
        } catch (CircuitOpen) {
            self::addToAssertionCount(1);
        }

        self::assertCount($requestsBefore, $this->emulator->stats()['charges'], 'the sixth call never reached the emulator');
        self::assertArrayNotHasKey('pay-6', $this->emulator->stats()['charges']);

        // The gateway is healthy again and 30 s pass: one probe closes the circuit.
        $this->emulator->mode('ok');
        $this->clock->sleep(30);

        $result = $this->gateway->charge('pay-6', $this->money());

        self::assertSame(GatewayChargeStatus::Succeeded, $result->status);
        self::assertSame(CircuitState::Closed, $this->breaker->state());
    }

    public function testBankDeclinesNeverOpenTheCircuit(): void
    {
        $this->emulator->mode('decline');

        for ($i = 1; $i <= 12; ++$i) {
            self::assertSame(GatewayChargeStatus::Declined, $this->gateway->charge('pay-' . $i, $this->money())->status);
        }

        self::assertSame(CircuitState::Closed, $this->breaker->state());
    }

    private function money(): Money
    {
        return Money::positive(1999, Currency::EUR);
    }
}
