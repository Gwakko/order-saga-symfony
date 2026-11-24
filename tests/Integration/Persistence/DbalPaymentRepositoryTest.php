<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentStatus;
use App\Shared\Application\Exception\ConcurrencyConflict;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\PaymentId;
use App\Shared\Domain\Money;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\Support\ForkedRunner;
use App\Tests\Integration\Support\Infra;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;

#[Group('M4.2')]
final class DbalPaymentRepositoryTest extends IntegrationTestCase
{
    private function newPayment(?OrderId $orderId = null): Payment
    {
        return Payment::create(PaymentId::fromString(self::uuid()), $orderId ?? OrderId::fromString(self::uuid()), Money::positive(1999, Currency::EUR), $this->t0);
    }

    #[Group('T-I-11')]
    #[Group('concurrency')]
    public function testConcurrentInsertOrGet(): void
    {
        for ($round = 1; $round <= 20; ++$round) {
            $orderId = OrderId::fromString(self::uuid());

            $job = static function (Connection $connection) use ($orderId): string {
                $now = new \DateTimeImmutable('2026-05-01T10:00:00Z');
                $payment = Payment::create(PaymentId::fromString(self::uuid()), $orderId, Money::positive(1999, Currency::EUR), $now);

                return (new Infra($connection))->payments->insertOrGet($payment)->id->value;
            };

            $results = ForkedRunner::run($this->connection, [$job, $job, $job]);

            self::assertCount(1, array_unique($results), 'all callers must see one payment id: ' . implode(', ', $results));
            self::assertStringStartsNotWith('EXCEPTION', $results[0]);
            self::assertSame(1, $this->countRows('payments', sprintf("order_id = '%s'", $orderId->value)));
        }
    }

    public function testInsertOrGetReturnsTheExistingPaymentOfTheOrder(): void
    {
        $first = $this->newPayment();
        $second = $this->newPayment($first->orderId);

        self::assertSame($first->id->value, $this->infra->payments->insertOrGet($first)->id->value);
        self::assertSame($first->id->value, $this->infra->payments->insertOrGet($second)->id->value);
        self::assertSame(1, $this->countRows('payments'));
    }

    public function testSaveIsACompareAndSwapOnTheVersion(): void
    {
        $payment = $this->infra->payments->insertOrGet($this->newPayment());
        $now = $this->t0->modify('+1 minute');

        $attempted = $payment->recordAttempt($now);
        $this->infra->payments->save($attempted);

        $stored = $this->infra->payments->findByOrder($payment->orderId);
        self::assertNotNull($stored);
        self::assertSame(1, $stored->attempts);
        self::assertSame(2, $stored->version);

        $this->expectException(ConcurrencyConflict::class);
        $this->infra->payments->save($payment->markDeclined('insufficient_funds', $now));
    }

    public function testPaymentEventsGoToTheOutbox(): void
    {
        $payment = $this->infra->payments->insertOrGet($this->newPayment());
        $this->infra->payments->save($payment->markDeclined('insufficient_funds', $this->t0->modify('+1 minute')));

        self::assertSame(1, $this->countRows('outbox_messages', "event_type = 'PaymentDeclined' AND aggregate_type = 'payment'"));
    }

    public function testFindOpenUpdatedBeforeSelectsOnlyOpenStatusesOldestFirst(): void
    {
        $unknown = $this->infra->payments->insertOrGet($this->newPayment());
        $this->infra->payments->save($unknown->markUnknown('timeout', $this->t0->modify('+1 minute')));

        $declined = $this->infra->payments->insertOrGet($this->newPayment());
        $this->infra->payments->save($declined->markDeclined('no', $this->t0->modify('+1 minute')));

        $recentlyUnknown = $this->infra->payments->insertOrGet($this->newPayment());
        $this->infra->payments->save($recentlyUnknown->markUnknown('timeout', $this->t0->modify('+10 minutes')));

        $abandoned = $this->infra->payments->insertOrGet($this->newPayment());
        $this->infra->payments->save($abandoned->abandon($this->t0->modify('+2 minutes')));

        $found = $this->infra->payments->findOpenUpdatedBefore($this->t0->modify('+5 minutes'), 10);

        self::assertSame([PaymentStatus::Unknown, PaymentStatus::Abandoned], array_map(static fn(Payment $p): PaymentStatus => $p->status, $found));
        self::assertCount(1, $this->infra->payments->findOpenUpdatedBefore($this->t0->modify('+5 minutes'), 1));
    }
}
