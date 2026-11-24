<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Domain;

use App\Payment\Domain\Event\PaymentCapturedWithMismatch;
use App\Payment\Domain\Exception\IllegalPaymentTransition;
use App\Payment\Domain\Exception\InvalidGatewayReference;
use App\Payment\Domain\Exception\PaymentAlreadySucceeded;
use App\Payment\Domain\MismatchKind;
use App\Payment\Domain\MismatchReason;
use App\Payment\Domain\PaymentStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Domain level of T-U-35 (EC-36, currency) and T-U-36 (EC-37, amount): markCapturedWithMismatch.
 */
#[Group('T-U-35')]
#[Group('T-U-36')]
final class PaymentCapturedWithMismatchTest extends TestCase
{
    private static function currencyReason(): MismatchReason
    {
        return new MismatchReason(MismatchKind::Currency, Money::of(1999, Currency::USD));
    }

    private static function amountReason(): MismatchReason
    {
        return new MismatchReason(MismatchKind::Amount, Money::of(2099, Currency::EUR));
    }

    /**
     * @return iterable<string, array{PaymentStatus}>
     */
    public static function openOutcomes(): iterable
    {
        yield 'pending' => [PaymentStatus::Pending];
        yield 'unknown' => [PaymentStatus::Unknown];
        yield 'abandoned' => [PaymentStatus::Abandoned];
    }

    /**
     * @return iterable<string, array{PaymentStatus}>
     */
    public static function otherStatuses(): iterable
    {
        yield 'succeeded' => [PaymentStatus::Succeeded];
        yield 'declined' => [PaymentStatus::Declined];
        yield 'refunded' => [PaymentStatus::Refunded];
    }

    #[DataProvider('openOutcomes')]
    public function testOpenOutcomeGoesStraightToRefundPending(PaymentStatus $from): void
    {
        $payment = PaymentFactory::inStatus($from);

        $result = $payment->markCapturedWithMismatch('ch_usd', self::currencyReason(), PaymentFactory::at(10));

        self::assertSame(PaymentStatus::RefundPending, $result->status);
        self::assertSame('ch_usd', $result->gatewayChargeId);
        self::assertSame($payment->version + 1, $result->version);
        self::assertSame('currency_mismatch: charged USD 1999', $result->lastError);
        self::assertEquals(self::currencyReason(), $result->capturedMismatch());
        // INV-18: the requested amount is never replaced by what the gateway reports.
        self::assertTrue($result->amount->equals(Money::positive(1999, Currency::EUR)));
        // The original is untouched.
        self::assertSame($from, $payment->status);
    }

    public function testEventCarriesChargeIdAndWhatWasActuallyCharged(): void
    {
        $result = PaymentFactory::pending()->markCapturedWithMismatch('ch_usd', self::currencyReason(), PaymentFactory::at(10));

        self::assertCount(1, $result->pendingEvents);
        $event = $result->pendingEvents[0];
        self::assertInstanceOf(PaymentCapturedWithMismatch::class, $event);
        self::assertSame('ch_usd', $event->chargeId);
        self::assertSame(MismatchKind::Currency, $event->reason->kind);
        self::assertSame(Currency::USD, $event->reason->charged->currency);
        self::assertSame(1999, $event->reason->charged->amount);
        self::assertTrue($event->amount->equals(Money::positive(1999, Currency::EUR)));
        self::assertSame($result->version, $event->aggregateVersion());
    }

    public function testAmountMismatchIsRecordedTheSameWay(): void
    {
        $result = PaymentFactory::inStatus(PaymentStatus::Unknown)->markCapturedWithMismatch('ch_big', self::amountReason(), PaymentFactory::at(10));

        self::assertSame(PaymentStatus::RefundPending, $result->status);
        self::assertSame('amount_mismatch: charged EUR 2099', $result->lastError);
        self::assertEquals(self::amountReason(), $result->capturedMismatch());
    }

    public function testRefundCanBeCompletedAfterwards(): void
    {
        $refunded = PaymentFactory::pending()
            ->markCapturedWithMismatch('ch_usd', self::currencyReason(), PaymentFactory::at(10))
            ->markRefunded('rf_1', PaymentFactory::at(11));

        self::assertSame(PaymentStatus::Refunded, $refunded->status);
        self::assertSame('ch_usd', $refunded->gatewayChargeId);
    }

    public function testRepeatWithSameChargeIdIsNoOp(): void
    {
        $first = PaymentFactory::pending()->markCapturedWithMismatch('ch_usd', self::currencyReason(), PaymentFactory::at(10));

        self::assertSame($first, $first->markCapturedWithMismatch('ch_usd', self::currencyReason(), PaymentFactory::at(20)));
    }

    public function testRepeatWithAnotherChargeIdIsRejected(): void
    {
        $first = PaymentFactory::pending()->markCapturedWithMismatch('ch_usd', self::currencyReason(), PaymentFactory::at(10));

        $this->expectException(PaymentAlreadySucceeded::class);

        $first->markCapturedWithMismatch('ch_other', self::currencyReason(), PaymentFactory::at(20));
    }

    #[DataProvider('otherStatuses')]
    public function testOtherStatusesAreRejected(PaymentStatus $from): void
    {
        $payment = PaymentFactory::inStatus($from);

        try {
            $payment->markCapturedWithMismatch('ch_usd', self::currencyReason(), PaymentFactory::at(100));
            self::fail('Expected IllegalPaymentTransition');
        } catch (IllegalPaymentTransition $e) {
            self::assertSame($from, $e->from);
            self::assertSame(PaymentStatus::RefundPending, $e->to);
        }
    }

    public function testRequestRefundStillNeedsASuccess(): void
    {
        foreach ([PaymentStatus::Pending, PaymentStatus::Unknown, PaymentStatus::Abandoned] as $from) {
            try {
                PaymentFactory::inStatus($from)->requestRefund(PaymentFactory::at(100));
                self::fail('Expected IllegalPaymentTransition from ' . $from->value);
            } catch (IllegalPaymentTransition $e) {
                self::assertSame($from, $e->from);
            }
        }
    }

    public function testEmptyChargeIdIsRejected(): void
    {
        $this->expectException(InvalidGatewayReference::class);

        PaymentFactory::pending()->markCapturedWithMismatch(' ', self::currencyReason(), PaymentFactory::at(10));
    }

    public function testLastErrorWithoutKnownKindIsNotAMismatch(): void
    {
        self::assertNull(MismatchReason::fromLastError(null));
        self::assertNull(MismatchReason::fromLastError('timeout'));
        self::assertNull(MismatchReason::fromLastError('currency_mismatch: charged XXX 5'));
        self::assertNull(PaymentFactory::inStatus(PaymentStatus::RefundPending)->capturedMismatch());
    }
}
