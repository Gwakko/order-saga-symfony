<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Gateway;

use App\Payment\Application\Port\ChargeResult;
use App\Payment\Application\Port\PaymentGateway;
use App\Payment\Application\Port\RefundResult;
use App\Shared\Domain\Money;

/**
 * Sends every gateway call through one shared circuit breaker. No retries here: Temporal retries (EC-31).
 */
final readonly class CircuitBreakingPaymentGateway implements PaymentGateway
{
    public function __construct(private PaymentGateway $inner, private CircuitBreaker $breaker) {}

    public function charge(string $idempotencyKey, Money $amount): ChargeResult
    {
        return $this->breaker->call(fn(): ChargeResult => $this->inner->charge($idempotencyKey, $amount));
    }

    public function findCharge(string $idempotencyKey): ?ChargeResult
    {
        return $this->breaker->call(fn(): ?ChargeResult => $this->inner->findCharge($idempotencyKey));
    }

    public function refund(string $idempotencyKey, string $chargeId, Money $amount): RefundResult
    {
        return $this->breaker->call(fn(): RefundResult => $this->inner->refund($idempotencyKey, $chargeId, $amount));
    }
}
