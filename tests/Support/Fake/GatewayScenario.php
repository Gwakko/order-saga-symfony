<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

/**
 * What `FakePaymentGateway::charge` does on one call.
 */
enum GatewayScenario
{
    /** Charges the requested amount. */
    case Succeed;

    /** Bank refusal (not a failure of the gateway). */
    case Decline;

    /** The charge is booked, but the answer is lost (EC-24, EC-27). */
    case TimeoutAfterCharge;

    /** The request never reached the bank, the answer is lost. */
    case TimeoutBeforeCharge;

    /** 5xx answer, nothing booked. */
    case Unavailable;

    /** The breaker rejects the call, nothing is sent (EC-32). */
    case CircuitOpen;

    /** Succeeds, but reports USD instead of the requested currency (EC-36). */
    case WrongCurrency;

    /** Succeeds, but reports one minor unit more than requested (EC-37). */
    case WrongAmount;
}
