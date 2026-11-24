<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messaging;

use App\Shared\Application\Exception\ConcurrencyConflict;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * EC-51, INV-10: state and events commit together or not at all.
 */
#[Group('M4.3')]
final class TransactionalOutboxTest extends IntegrationTestCase
{
    #[Group('T-I-31')]
    public function testRollbackLeavesNoMessage(): void
    {
        $order = $this->placeOrder();

        try {
            $this->infra->transactions->transactional(function () use ($order): void {
                $this->infra->orders->save($order);

                if ($this->connection->isTransactionActive()) {
                    throw new \RuntimeException('failure after save');
                }
            });
            self::fail('The exception must propagate.');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertSame(0, $this->countRows('orders'));
        self::assertSame(0, $this->countRows('outbox_messages'));
    }

    public function testCommitKeepsStateAndMessageTogether(): void
    {
        $order = $this->placeOrder();
        $this->infra->transactions->transactional(fn() => $this->infra->orders->save($order));

        self::assertSame(1, $this->countRows('orders'));
        self::assertSame(1, $this->countRows('outbox_messages'));
    }

    public function testAFailedWriteInsideAUnitOfWorkRollsBackTheEarlierOnes(): void
    {
        $first = $this->placeOrder();
        $this->infra->orders->save($first);
        $stale = $this->infra->orders->get($first->id);
        $this->infra->orders->save($this->infra->orders->get($first->id)->requestCancellation($this->t0->modify('+1 minute')));
        $outbox = $this->countRows('outbox_messages');

        $second = $this->placeOrder();

        try {
            $this->infra->transactions->transactional(function () use ($second, $stale): void {
                $this->infra->orders->save($second);
                $this->infra->orders->save($stale->requestCancellation($this->t0->modify('+2 minutes')));
            });
            self::fail('The stale write must fail.');
        } catch (ConcurrencyConflict) {
            // expected
        }

        self::assertSame(1, $this->countRows('orders'));
        self::assertSame($outbox, $this->countRows('outbox_messages'));
    }

    public function testWithoutAnOuterTransactionASingleSaveIsStillAtomic(): void
    {
        $order = $this->placeOrder();
        $this->infra->orders->save($order);
        $stale = $this->infra->orders->get($order->id);
        $this->infra->orders->save($this->infra->orders->get($order->id)->requestCancellation($this->t0->modify('+1 minute')));
        $before = $this->countRows('outbox_messages');

        try {
            $this->infra->orders->save($stale->requestCancellation($this->t0->modify('+2 minutes')));
            self::fail('conflict expected');
        } catch (ConcurrencyConflict) {
            self::assertSame($before, $this->countRows('outbox_messages'));
            self::assertFalse($this->connection->isTransactionActive());
        }
    }
}
