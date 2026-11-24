<?php

declare(strict_types=1);

namespace App\Tests\Unit\Saga;

use App\Inventory\Application\Exception\KeyIsRevoked;
use App\Inventory\Application\Exception\OutOfStock;
use App\Inventory\Domain\Exception\ReservationHasExpired;
use App\Payment\Application\Exception\AmountMismatch;
use App\Payment\Application\Exception\CircuitOpen;
use App\Payment\Application\Exception\PaymentDeclined;
use App\Payment\Application\Exception\ReservationNotActive;
use App\Saga\Infrastructure\Temporal\Workflow\FailureClassifier;
use App\Saga\Infrastructure\Temporal\Workflow\SagaStep;
use App\Shared\Domain\Exception\CurrencyMismatch;
use App\Shared\Domain\FailureReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Temporal\DataConverter\EncodedValues;
use Temporal\Exception\Failure\ActivityFailure;
use Temporal\Exception\Failure\ApplicationFailure;
use Temporal\Exception\Failure\TimeoutFailure;

/** Maps the exception class carried by a failed activity to the reason the order ends with (EC-14, 16, 18, 29, 34, 36). */
#[Group('M6.2')]
final class FailureClassifierTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<\Throwable>, SagaStep, FailureReason}>
     */
    public static function businessOutcomes(): iterable
    {
        yield 'out of stock' => [OutOfStock::class, SagaStep::ReserveKey, FailureReason::OutOfStock];
        yield 'declined' => [PaymentDeclined::class, SagaStep::ChargePayment, FailureReason::PaymentDeclined];
        yield 'currency mismatch' => [CurrencyMismatch::class, SagaStep::ChargePayment, FailureReason::CurrencyMismatch];
        yield 'amount mismatch' => [AmountMismatch::class, SagaStep::ChargePayment, FailureReason::CurrencyMismatch];
        yield 'reservation too short to charge' => [ReservationNotActive::class, SagaStep::ChargePayment, FailureReason::ReservationExpired];
        yield 'reservation expired on consume' => [ReservationHasExpired::class, SagaStep::ConsumeReservation, FailureReason::ReservationExpired];
        yield 'key revoked' => [KeyIsRevoked::class, SagaStep::ConsumeReservation, FailureReason::KeyRevoked];
    }

    /**
     * @param class-string<\Throwable> $type
     */
    #[DataProvider('businessOutcomes')]
    public function testBusinessExceptionBecomesItsReason(string $type, SagaStep $step, FailureReason $expected): void
    {
        self::assertSame($expected, (new FailureClassifier())->reasonFor($this->activityFailed(new ApplicationFailure('x', $type, true)), $step));
    }

    public function testExhaustedChargeBecomesPaymentUnavailable(): void
    {
        $classifier = new FailureClassifier();

        $retriesExhausted = $this->activityFailed(new TimeoutFailure('schedule to close', EncodedValues::empty(), 2, new ApplicationFailure('open', CircuitOpen::class, false)));
        $lastError = $this->activityFailed(new ApplicationFailure('open', CircuitOpen::class, false));

        self::assertSame(FailureReason::PaymentUnavailable, $classifier->reasonFor($retriesExhausted, SagaStep::ChargePayment));
        self::assertSame(FailureReason::PaymentUnavailable, $classifier->reasonFor($lastError, SagaStep::ChargePayment));
    }

    public function testUnknownFailureOutsideTheChargeIsNotABusinessOutcome(): void
    {
        $failure = $this->activityFailed(new ApplicationFailure('db down', \RuntimeException::class, false));

        self::assertNull((new FailureClassifier())->reasonFor($failure, SagaStep::ReserveKey));
        self::assertNull((new FailureClassifier())->reasonFor($failure, SagaStep::ConsumeReservation));
    }

    private function activityFailed(\Throwable $cause): ActivityFailure
    {
        return new ActivityFailure(1, 2, 'Activity', '1', 0, 'test', $cause);
    }
}
