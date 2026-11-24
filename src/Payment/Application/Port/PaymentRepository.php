<?php

declare(strict_types=1);

namespace App\Payment\Application\Port;

use App\Payment\Domain\Payment;
use App\Shared\Application\Exception\ConcurrencyConflict;
use App\Shared\Domain\Identifier\OrderId;

interface PaymentRepository
{
    public function findByOrder(OrderId $orderId): ?Payment;

    /**
     * Inserts the payment, or returns the one that already exists for the order when the unique index
     * `payments(order_id)` wins a race (INV-06).
     */
    public function insertOrGet(Payment $payment): Payment;

    /**
     * CAS on the version, pending events go to the outbox in the same transaction.
     *
     * @throws ConcurrencyConflict
     */
    public function save(Payment $payment): void;

    /**
     * `Unknown`, `Abandoned` and `RefundPending` payments last updated before `$before`, oldest first (reconciliation).
     *
     * @return list<Payment>
     */
    public function findOpenUpdatedBefore(\DateTimeImmutable $before, int $limit): array;
}
