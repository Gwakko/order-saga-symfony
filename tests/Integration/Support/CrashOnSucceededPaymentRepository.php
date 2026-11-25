<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use App\Payment\Application\Port\PaymentRepository;
use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentStatus;
use App\Shared\Domain\Identifier\OrderId;

/**
 * The worker dies the moment it would store `Succeeded`: the gateway has already charged, the database has not heard of it.
 */
final class CrashOnSucceededPaymentRepository implements PaymentRepository
{
    private bool $crashed = false;

    public function __construct(private readonly PaymentRepository $inner) {}

    public function findByOrder(OrderId $orderId): ?Payment
    {
        return $this->inner->findByOrder($orderId);
    }

    public function insertOrGet(Payment $payment): Payment
    {
        return $this->inner->insertOrGet($payment);
    }

    public function save(Payment $payment): void
    {
        if (!$this->crashed && $payment->status === PaymentStatus::Succeeded) {
            $this->crashed = true;

            throw new \RuntimeException('simulated worker crash before the payment was saved');
        }

        $this->inner->save($payment);
    }

    public function findOpenUpdatedBefore(\DateTimeImmutable $before, int $limit): array
    {
        return $this->inner->findOpenUpdatedBefore($before, $limit);
    }
}
