<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Stable machine-readable error codes (02_use_cases.md section 6). The HTTP mapping lives in infrastructure.
 */
enum DomainErrorCode: string
{
    case ValidationFailed = 'VALIDATION_FAILED';
    case CustomerRequired = 'CUSTOMER_REQUIRED';
    case IdempotencyKeyMissing = 'IDEMPOTENCY_KEY_MISSING';
    case IdempotencyKeyInvalid = 'IDEMPOTENCY_KEY_INVALID';
    case IdempotencyKeyReused = 'IDEMPOTENCY_KEY_REUSED';
    case IdempotentRequestInProgress = 'IDEMPOTENT_REQUEST_IN_PROGRESS';
    case NegativeMoney = 'NEGATIVE_MONEY';
    case NonPositiveMoney = 'NON_POSITIVE_MONEY';
    case MoneyOutOfRange = 'MONEY_OUT_OF_RANGE';
    case MoneyOverflow = 'MONEY_OVERFLOW';
    case UnknownCurrency = 'UNKNOWN_CURRENCY';
    case CurrencyMismatch = 'CURRENCY_MISMATCH';
    case PriceChanged = 'PRICE_CHANGED';
    case ProductNotFound = 'PRODUCT_NOT_FOUND';
    case ProductUnavailable = 'PRODUCT_UNAVAILABLE';
    case OrderNotFound = 'ORDER_NOT_FOUND';
    case IllegalOrderTransition = 'ILLEGAL_ORDER_TRANSITION';
    case OrderNotCancellable = 'ORDER_NOT_CANCELLABLE';
    case OrderNotRefundable = 'ORDER_NOT_REFUNDABLE';
    case RefundWindowClosed = 'REFUND_WINDOW_CLOSED';
    case ConcurrencyConflict = 'CONCURRENCY_CONFLICT';
    case OutOfStock = 'OUT_OF_STOCK';
    case ReservationExpired = 'RESERVATION_EXPIRED';
    case ReservationAlreadyConsumed = 'RESERVATION_ALREADY_CONSUMED';
    case KeyRevoked = 'KEY_REVOKED';
    case PaymentDeclined = 'PAYMENT_DECLINED';
    case PaymentAlreadySucceeded = 'PAYMENT_ALREADY_SUCCEEDED';
    case PaymentGatewayUnavailable = 'PAYMENT_GATEWAY_UNAVAILABLE';
    case CircuitOpen = 'CIRCUIT_OPEN';
    case PaymentOutcomeUnknown = 'PAYMENT_OUTCOME_UNKNOWN';
    case InvalidCursor = 'INVALID_CURSOR';

    // Not listed in the spec table: needed for domain rules that have no code there (see docs/reports/M2.1.md).
    case InvalidIdentifier = 'INVALID_IDENTIFIER';
    case InvalidKeyCode = 'INVALID_KEY_CODE';
    case InvalidRefundWindow = 'INVALID_REFUND_WINDOW';
    case IllegalKeyTransition = 'ILLEGAL_KEY_TRANSITION';
    case IllegalReservationTransition = 'ILLEGAL_RESERVATION_TRANSITION';
    case IllegalPaymentTransition = 'ILLEGAL_PAYMENT_TRANSITION';
    case InvalidGatewayReference = 'INVALID_GATEWAY_REFERENCE';
    case InvariantViolated = 'INVARIANT_VIOLATED';
}
