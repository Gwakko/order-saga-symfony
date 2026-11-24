<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Domain;

use App\Payment\Domain\Event\OrphanChargeDetected;
use App\Payment\Domain\Event\PaymentDeclined;
use App\Payment\Domain\Event\PaymentRefunded;
use App\Payment\Domain\Event\PaymentSucceeded;
use App\Payment\Domain\Exception\IllegalPaymentTransition;
use App\Payment\Domain\Exception\InvalidGatewayReference;
use App\Payment\Domain\Exception\PaymentAlreadySucceeded;
use App\Payment\Domain\Exception\PaymentInvariantViolated;
use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;
use App\Shared\Domain\Exception\CurrencyMismatch;
use App\Shared\Domain\Exception\NonPositiveMoney;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\PaymentId;
use App\Shared\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * T-U-14: PaymentStatus transition table, Abandoned -> Succeeded, repeated markSucceeded (INV-06, EC-36, EC-37, EC-49).
 */
#[Group('T-U-14')]
final class PaymentTest extends TestCase
{
    /**
     * @return iterable<string, array{PaymentStatus, PaymentStatus, bool}>
     */
    public static function transitionTable(): iterable
    {
        // Transcribed by hand from the spec table (01_domain.md section 5).
        $allowed = [
            'pending' => ['unknown', 'succeeded', 'declined', 'abandoned'],
            'unknown' => ['succeeded', 'declined', 'abandoned'],
            'succeeded' => ['refund_pending'],
            'declined' => [],
            'abandoned' => ['succeeded'],
            'refund_pending' => ['refunded'],
            'refunded' => [],
        ];

        foreach (PaymentStatus::cases() as $from) {
            foreach (PaymentStatus::cases() as $to) {
                yield $from->value . ' -> ' . $to->value => [$from, $to, in_array($to->value, $allowed[$from->value], true)];
            }
        }
    }

    #[DataProvider('transitionTable')]
    public function testStatusTableMatchesSpec(PaymentStatus $from, PaymentStatus $to, bool $expected): void
    {
        self::assertSame($expected, $from->canTransitionTo($to));
    }

    #[DataProvider('transitionTable')]
    public function testAggregateMatchesSpec(PaymentStatus $from, PaymentStatus $to, bool $expected): void
    {
        $payment = PaymentFactory::inStatus($from);
        $now = PaymentFactory::at(100);

        // Fresh ids on purpose: idempotent repeats (same ids) are covered separately and must not look like transitions.
        $attempt = match ($to) {
            PaymentStatus::Pending => null,
            PaymentStatus::Unknown => fn(): Payment => $payment->markUnknown('timeout', $now),
            PaymentStatus::Succeeded => fn(): Payment => $payment->markSucceeded('ch_other', Currency::EUR, $now),
            PaymentStatus::Declined => fn(): Payment => $payment->markDeclined('declined', $now),
            PaymentStatus::Abandoned => fn(): Payment => $payment->abandon($now),
            PaymentStatus::RefundPending => fn(): Payment => $payment->requestRefund($now),
            PaymentStatus::Refunded => fn(): Payment => $payment->markRefunded('rf_other', $now),
        };

        if ($attempt === null) {
            self::assertFalse($expected);

            return;
        }

        try {
            $result = $attempt();
        } catch (DomainError $e) {
            self::assertFalse($expected, 'spec allows this transition but the aggregate refused it: ' . $e->getMessage());

            return;
        }

        if ($result === $payment) {
            self::assertFalse($expected, 'a transition must produce a new instance');

            return;
        }

        self::assertTrue($expected, 'spec forbids this transition but the aggregate allowed it');
        self::assertSame($to, $result->status);
        self::assertSame($payment->version + 1, $result->version);
    }

    #[DataProvider('transitionTable')]
    public function testForbiddenTransitionsRaiseTypedError(PaymentStatus $from, PaymentStatus $to, bool $expected): void
    {
        if ($expected || $from === $to || $to === PaymentStatus::Pending) {
            $this->expectNotToPerformAssertions();

            return;
        }

        $payment = PaymentFactory::inStatus($from);
        $now = PaymentFactory::at(100);

        try {
            match ($to) {
                PaymentStatus::Unknown => $payment->markUnknown('timeout', $now),
                PaymentStatus::Succeeded => $payment->markSucceeded('ch_other', Currency::EUR, $now),
                PaymentStatus::Declined => $payment->markDeclined('declined', $now),
                PaymentStatus::Abandoned => $payment->abandon($now),
                PaymentStatus::RefundPending => $payment->requestRefund($now),
                PaymentStatus::Refunded => $payment->markRefunded('rf_other', $now),
            };
            self::fail('Expected a domain error');
        } catch (IllegalPaymentTransition $e) {
            self::assertSame(DomainErrorCode::IllegalPaymentTransition, $e->code());
            self::assertSame($from, $e->from);
            self::assertSame($to, $e->to);
        }
    }

    public function testCreateIsPendingWithNoReferences(): void
    {
        $payment = PaymentFactory::pending();

        self::assertSame(PaymentStatus::Pending, $payment->status);
        self::assertSame(1, $payment->version);
        self::assertSame(0, $payment->attempts);
        self::assertNull($payment->gatewayChargeId);
        self::assertNull($payment->gatewayRefundId);
        self::assertSame([], $payment->pendingEvents);
    }

    public function testZeroAmountRejected(): void
    {
        $this->expectException(NonPositiveMoney::class);
        Payment::create(
            PaymentId::fromString(PaymentFactory::PAYMENT_ID),
            OrderId::fromString(PaymentFactory::ORDER_ID),
            Money::of(0, Currency::EUR),
            PaymentFactory::t0(),
        );
    }

    public function testMarkUnknownRepeatIsNoOp(): void
    {
        $unknown = PaymentFactory::inStatus(PaymentStatus::Unknown);

        self::assertSame($unknown, $unknown->markUnknown('connection reset', PaymentFactory::at(50)));
    }

    public function testRecordAttemptCountsWhileOutcomeIsOpen(): void
    {
        $once = PaymentFactory::pending()->recordAttempt(PaymentFactory::at(1));
        $twice = $once->markUnknown('timeout', PaymentFactory::at(2))->recordAttempt(PaymentFactory::at(3));

        self::assertSame(1, $once->attempts);
        self::assertSame(2, $twice->attempts);
        self::assertSame(PaymentStatus::Unknown, $twice->status);

        $this->expectException(IllegalPaymentTransition::class);
        PaymentFactory::inStatus(PaymentStatus::Succeeded)->recordAttempt(PaymentFactory::at(9));
    }

    /**
     * INV-06: repeating markSucceeded with the same charge id is a no-op, with another one it is refused.
     */
    public function testRepeatedMarkSucceededWithSameChargeIsNoOp(): void
    {
        $succeeded = PaymentFactory::inStatus(PaymentStatus::Succeeded);

        self::assertSame($succeeded, $succeeded->markSucceeded(PaymentFactory::CHARGE_ID, Currency::EUR, PaymentFactory::at(50)));
    }

    public function testMarkSucceededWithAnotherChargeRejected(): void
    {
        $succeeded = PaymentFactory::inStatus(PaymentStatus::Succeeded);

        try {
            $succeeded->markSucceeded('ch_2', Currency::EUR, PaymentFactory::at(50));
            self::fail('Expected PaymentAlreadySucceeded');
        } catch (PaymentAlreadySucceeded $e) {
            self::assertSame(DomainErrorCode::PaymentAlreadySucceeded, $e->code());
        }

        self::assertSame(PaymentFactory::CHARGE_ID, $succeeded->gatewayChargeId);
    }

    public function testSucceededFromPendingAndUnknownRaisesPaymentSucceeded(): void
    {
        foreach ([PaymentStatus::Pending, PaymentStatus::Unknown] as $from) {
            $result = PaymentFactory::inStatus($from)->markSucceeded('ch_9', Currency::EUR, PaymentFactory::at(10));

            self::assertSame(PaymentStatus::Succeeded, $result->status);
            self::assertSame('ch_9', $result->gatewayChargeId);
            self::assertInstanceOf(PaymentSucceeded::class, $result->pendingEvents[0]);
            self::assertSame(1999, $result->pendingEvents[0]->amount->amount);
        }
    }

    /**
     * EC-28: success after the payment was abandoned is recorded as an orphan charge, ready to be refunded.
     */
    public function testAbandonedToSucceededRecordsOrphanChargeAndCanBeRefunded(): void
    {
        $orphan = PaymentFactory::inStatus(PaymentStatus::Abandoned)->markSucceeded('ch_late', Currency::EUR, PaymentFactory::at(10));

        self::assertSame(PaymentStatus::Succeeded, $orphan->status);
        self::assertSame('ch_late', $orphan->gatewayChargeId);
        self::assertInstanceOf(OrphanChargeDetected::class, $orphan->pendingEvents[0]);

        $refund = $orphan->requestRefund(PaymentFactory::at(11));
        self::assertSame(PaymentStatus::RefundPending, $refund->status);
    }

    /**
     * EC-36: the gateway answers in another currency; the success is not recorded.
     */
    public function testCurrencyMismatchInGatewayResponse(): void
    {
        foreach ([PaymentStatus::Pending, PaymentStatus::Unknown] as $from) {
            $payment = PaymentFactory::inStatus($from);

            try {
                $payment->markSucceeded('ch_usd', Currency::USD, PaymentFactory::at(10));
                self::fail('Expected CurrencyMismatch');
            } catch (CurrencyMismatch $e) {
                self::assertSame(DomainErrorCode::CurrencyMismatch, $e->code());
            }

            self::assertSame($from, $payment->status);
        }
    }

    public function testRefundOnlyAfterSuccessAndOnlyOnce(): void
    {
        foreach ([PaymentStatus::Pending, PaymentStatus::Unknown, PaymentStatus::Declined, PaymentStatus::Abandoned] as $status) {
            try {
                PaymentFactory::inStatus($status)->requestRefund(PaymentFactory::at(10));
                self::fail('Expected IllegalPaymentTransition from ' . $status->value);
            } catch (IllegalPaymentTransition) {
                // expected: no refund without a successful charge
            }
        }

        $refundPending = PaymentFactory::inStatus(PaymentStatus::RefundPending);
        $this->expectException(IllegalPaymentTransition::class);
        $refundPending->requestRefund(PaymentFactory::at(10));
    }

    public function testRefundCoversTheFullAmount(): void
    {
        $refunded = PaymentFactory::inStatus(PaymentStatus::Refunded);

        self::assertSame('rf_1', $refunded->gatewayRefundId);
        self::assertSame(1999, $refunded->amount->amount);
        self::assertInstanceOf(PaymentRefunded::class, $refunded->pendingEvents[1]);
        self::assertSame(1999, $refunded->pendingEvents[1]->amount->amount);
    }

    public function testDeclinedRaisesEventAndKeepsReason(): void
    {
        $declined = PaymentFactory::inStatus(PaymentStatus::Declined);

        self::assertSame('insufficient_funds', $declined->lastError);
        self::assertInstanceOf(PaymentDeclined::class, $declined->pendingEvents[0]);
    }

    public function testEmptyGatewayReferencesRejected(): void
    {
        try {
            PaymentFactory::pending()->markSucceeded(' ', Currency::EUR, PaymentFactory::at(1));
            self::fail('Expected InvalidGatewayReference');
        } catch (InvalidGatewayReference) {
            // expected
        }

        $this->expectException(InvalidGatewayReference::class);
        PaymentFactory::inStatus(PaymentStatus::RefundPending)->markRefunded('', PaymentFactory::at(5));
    }

    public function testReconstituteRejectsSucceededWithoutChargeId(): void
    {
        $this->expectException(PaymentInvariantViolated::class);
        Payment::reconstitute(
            PaymentId::fromString(PaymentFactory::PAYMENT_ID),
            OrderId::fromString(PaymentFactory::ORDER_ID),
            Money::positive(1999, Currency::EUR),
            PaymentStatus::Succeeded,
            null,
            null,
            1,
            null,
            PaymentFactory::t0(),
            PaymentFactory::t0(),
            2,
        );
    }

    public function testTransitionsDoNotMutateTheOriginal(): void
    {
        $pending = PaymentFactory::pending();
        $pending->markDeclined('no', PaymentFactory::at(1));

        self::assertSame(PaymentStatus::Pending, $pending->status);
        self::assertSame(1, $pending->version);
        self::assertSame([], $pending->pendingEvents);
    }
}
