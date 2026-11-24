<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Payment\Application\Exception\CircuitOpen;
use App\Payment\Application\Exception\PaymentGatewayUnavailable;
use App\Payment\Application\Exception\PaymentOutcomeUnknown;
use App\Payment\Application\Port\ChargeResult;
use App\Payment\Application\Port\GatewayChargeStatus;
use App\Payment\Application\Port\PaymentGateway;
use App\Payment\Application\Port\RefundResult;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;

/**
 * Scripted gateway with idempotency by key, like the emulator. Calls beyond the script succeed.
 */
final class FakePaymentGateway implements PaymentGateway
{
    public int $chargeCalls = 0;
    public int $findCalls = 0;
    public int $refundCalls = 0;
    public ?Money $lastChargeAmount = null;

    /** @var list<GatewayScenario> */
    private array $script = [];

    /** @var array<string, ChargeResult> */
    private array $charges = [];

    /** @var array<string, RefundResult> */
    private array $refunds = [];

    private int $refundFailures = 0;

    public function script(GatewayScenario ...$scenarios): void
    {
        $this->script = array_values($scenarios);
    }

    /**
     * The next `$times` refund calls fail with PaymentGatewayUnavailable (EC-45).
     */
    public function failRefunds(int $times): void
    {
        $this->refundFailures = $times;
    }

    /**
     * Test setup: the gateway already knows a charge (a late success, EC-28).
     */
    public function seedCharge(string $idempotencyKey, ChargeResult $charge): void
    {
        $this->charges[$idempotencyKey] = $charge;
    }

    /**
     * Number of distinct refunds the gateway booked.
     */
    public function bookedRefunds(): int
    {
        return count($this->refunds);
    }

    public function charge(string $idempotencyKey, Money $amount): ChargeResult
    {
        ++$this->chargeCalls;
        $this->lastChargeAmount = $amount;
        $scenario = array_shift($this->script) ?? GatewayScenario::Succeed;

        if (isset($this->charges[$idempotencyKey]) && $scenario !== GatewayScenario::TimeoutBeforeCharge) {
            return $this->charges[$idempotencyKey];
        }

        return match ($scenario) {
            GatewayScenario::Succeed => $this->book($idempotencyKey, GatewayChargeStatus::Succeeded, $amount),
            GatewayScenario::Decline => $this->book($idempotencyKey, GatewayChargeStatus::Declined, $amount, 'insufficient_funds'),
            GatewayScenario::TimeoutAfterCharge => $this->bookThenTimeout($idempotencyKey, $amount),
            GatewayScenario::TimeoutBeforeCharge => throw new PaymentOutcomeUnknown('timeout'),
            GatewayScenario::Unavailable => throw new PaymentGatewayUnavailable('gateway answered 503'),
            GatewayScenario::CircuitOpen => throw new CircuitOpen(),
            GatewayScenario::WrongCurrency => $this->book($idempotencyKey, GatewayChargeStatus::Succeeded, Money::of($amount->amount, Currency::USD)),
            GatewayScenario::WrongAmount => $this->book($idempotencyKey, GatewayChargeStatus::Succeeded, Money::of($amount->amount + 1, $amount->currency)),
        };
    }

    public function findCharge(string $idempotencyKey): ?ChargeResult
    {
        ++$this->findCalls;

        return $this->charges[$idempotencyKey] ?? null;
    }

    public function refund(string $idempotencyKey, string $chargeId, Money $amount): RefundResult
    {
        ++$this->refundCalls;

        if ($this->refundFailures > 0) {
            --$this->refundFailures;

            throw new PaymentGatewayUnavailable('gateway answered 503');
        }

        return $this->refunds[$idempotencyKey] ??= new RefundResult('rf_' . (count($this->refunds) + 1), $amount);
    }

    private function book(string $key, GatewayChargeStatus $status, Money $amount, ?string $declineReason = null): ChargeResult
    {
        return $this->charges[$key] = new ChargeResult('ch_' . (count($this->charges) + 1), $status, $amount, $declineReason);
    }

    private function bookThenTimeout(string $key, Money $amount): never
    {
        $this->book($key, GatewayChargeStatus::Succeeded, $amount);

        throw new PaymentOutcomeUnknown('timeout');
    }
}
