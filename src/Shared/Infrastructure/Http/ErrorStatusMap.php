<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Domain\DomainErrorCode;
use Symfony\Component\HttpFoundation\Response;

/**
 * Error code to HTTP status, shared by every edge that reports errors (REST problems, GraphQL `extensions`).
 * One exhaustive `match`: a new error code does not compile into the table by accident.
 */
final class ErrorStatusMap
{
    /**
     * Codes without an HTTP meaning are saga-internal: reaching a controller means a defect, so they map to 500.
     */
    public static function statusOf(DomainErrorCode $code): int
    {
        return match ($code) {
            DomainErrorCode::CustomerRequired => Response::HTTP_UNAUTHORIZED,
            DomainErrorCode::IdempotencyKeyMissing,
            DomainErrorCode::IdempotencyKeyInvalid => Response::HTTP_BAD_REQUEST,
            DomainErrorCode::ProductNotFound,
            DomainErrorCode::OrderNotFound => Response::HTTP_NOT_FOUND,
            DomainErrorCode::IdempotentRequestInProgress,
            DomainErrorCode::PriceChanged,
            DomainErrorCode::ProductUnavailable,
            DomainErrorCode::IllegalOrderTransition,
            DomainErrorCode::OrderNotCancellable,
            DomainErrorCode::OrderNotRefundable,
            DomainErrorCode::RefundWindowClosed,
            DomainErrorCode::ConcurrencyConflict => Response::HTTP_CONFLICT,
            DomainErrorCode::ValidationFailed,
            DomainErrorCode::IdempotencyKeyReused,
            DomainErrorCode::NegativeMoney,
            DomainErrorCode::NonPositiveMoney,
            DomainErrorCode::MoneyOutOfRange,
            DomainErrorCode::MoneyOverflow,
            DomainErrorCode::UnknownCurrency,
            DomainErrorCode::CurrencyMismatch,
            DomainErrorCode::InvalidCursor,
            DomainErrorCode::InvalidIdentifier => Response::HTTP_UNPROCESSABLE_ENTITY,
            DomainErrorCode::AmountMismatch,
            DomainErrorCode::OutOfStock,
            DomainErrorCode::ReservationExpired,
            DomainErrorCode::ReservationAlreadyConsumed,
            DomainErrorCode::KeyRevoked,
            DomainErrorCode::PaymentDeclined,
            DomainErrorCode::PaymentAlreadySucceeded,
            DomainErrorCode::PaymentGatewayUnavailable,
            DomainErrorCode::CircuitOpen,
            DomainErrorCode::PaymentOutcomeUnknown,
            DomainErrorCode::InvalidKeyCode,
            DomainErrorCode::InvalidRefundWindow,
            DomainErrorCode::IllegalKeyTransition,
            DomainErrorCode::IllegalReservationTransition,
            DomainErrorCode::IllegalPaymentTransition,
            DomainErrorCode::InvalidGatewayReference,
            DomainErrorCode::InvariantViolated => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
    }

    public static function httpCode(int $status): string
    {
        return match ($status) {
            Response::HTTP_BAD_REQUEST => 'BAD_REQUEST',
            Response::HTTP_NOT_FOUND => 'NOT_FOUND',
            Response::HTTP_METHOD_NOT_ALLOWED => 'METHOD_NOT_ALLOWED',
            Response::HTTP_NOT_ACCEPTABLE => 'NOT_ACCEPTABLE',
            Response::HTTP_REQUEST_ENTITY_TOO_LARGE => 'PAYLOAD_TOO_LARGE',
            Response::HTTP_UNSUPPORTED_MEDIA_TYPE => 'UNSUPPORTED_MEDIA_TYPE',
            Response::HTTP_UNPROCESSABLE_ENTITY => DomainErrorCode::ValidationFailed->value,
            default => $status >= Response::HTTP_INTERNAL_SERVER_ERROR ? 'INTERNAL_ERROR' : 'HTTP_ERROR',
        };
    }
}
