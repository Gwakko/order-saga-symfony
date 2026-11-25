<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment;

use App\Payment\Application\Query\PrepareChargeInspection\PrepareChargeInspectionHandler;
use App\Payment\Application\Query\PrepareChargeInspection\PrepareChargeInspectionQuery;
use App\Payment\Domain\Payment;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\PaymentId;
use App\Shared\Domain\Money;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * T-M9-06 (docs/spec/09_go_worker.md section 2.3): the saga asks the gateway about a charge only when the switch is on and
 * the stored payment's outcome is still open. Against Postgres, with the real repository.
 */
#[Group('M9.4')]
final class PrepareChargeInspectionHandlerTest extends IntegrationTestCase
{
    #[Group('T-M9-06')]
    public function testSwitchOffNeverInspects(): void
    {
        $payment = $this->store(static fn(Payment $p, \DateTimeImmutable $now): Payment => $p->markUnknown('timeout', $now));

        $output = $this->handler(false)(new PrepareChargeInspectionQuery($payment->orderId->value));

        self::assertFalse($output->inspect);
        self::assertSame(['inspect' => false, 'paymentId' => null, 'amount' => null, 'currency' => null], $output->toArray());
    }

    #[Group('T-M9-06')]
    public function testNoPaymentMeansNoInspection(): void
    {
        $output = $this->handler(true)(new PrepareChargeInspectionQuery(self::uuid()));

        self::assertFalse($output->inspect);
        self::assertNull($output->paymentId);
    }

    /** `Pending` (the charge may never have left) and `Unknown` (the gateway did not answer) carry the stored payment. */
    #[Group('T-M9-06')]
    #[DataProvider('openStatuses')]
    public function testAnOpenPaymentIsInspectedWithItsOwnIdAndMoney(string $case, \Closure $transition): void
    {
        $payment = $this->store($transition);

        $output = $this->handler(true)(new PrepareChargeInspectionQuery($payment->orderId->value));

        self::assertTrue($output->inspect, $case);
        self::assertSame(['inspect' => true, 'paymentId' => $payment->id->value, 'amount' => 1999, 'currency' => 'EUR'], $output->toArray());
    }

    /** @return iterable<string, array{string, \Closure(Payment, \DateTimeImmutable): Payment}> */
    public static function openStatuses(): iterable
    {
        yield 'pending' => ['pending', static fn(Payment $p, \DateTimeImmutable $now): Payment => $p];
        yield 'unknown' => ['unknown', static fn(Payment $p, \DateTimeImmutable $now): Payment => $p->markUnknown('timeout', $now)];
    }

    /** `Succeeded` (nothing to find out), `Declined` and `Abandoned` (decided): the saga keeps its behavior. */
    #[Group('T-M9-06')]
    #[DataProvider('decidedStatuses')]
    public function testADecidedPaymentIsNotInspected(string $case, \Closure $transition): void
    {
        $payment = $this->store($transition);

        $output = $this->handler(true)(new PrepareChargeInspectionQuery($payment->orderId->value));

        self::assertFalse($output->inspect, $case);
        self::assertNull($output->paymentId, $case);
    }

    /** @return iterable<string, array{string, \Closure(Payment, \DateTimeImmutable): Payment}> */
    public static function decidedStatuses(): iterable
    {
        yield 'succeeded' => ['succeeded', static fn(Payment $p, \DateTimeImmutable $now): Payment => $p->markSucceeded('ch_1', Currency::EUR, $now)];
        yield 'declined' => ['declined', static fn(Payment $p, \DateTimeImmutable $now): Payment => $p->markDeclined('insufficient_funds', $now)];
        yield 'abandoned' => ['abandoned', static fn(Payment $p, \DateTimeImmutable $now): Payment => $p->abandon($now)];
    }

    /** @param \Closure(Payment, \DateTimeImmutable): Payment $transition */
    private function store(\Closure $transition): Payment
    {
        $pending = Payment::create(PaymentId::fromString(self::uuid()), OrderId::fromString(self::uuid()), Money::positive(1999, Currency::EUR), $this->t0);
        $stored = $this->infra->payments->insertOrGet($pending);
        $next = $transition($stored, $this->t0);

        if ($next->version !== $stored->version) {
            $this->infra->payments->save($next);
        }

        return $next;
    }

    private function handler(bool $enabled): PrepareChargeInspectionHandler
    {
        return new PrepareChargeInspectionHandler($this->infra->payments, $enabled);
    }
}
