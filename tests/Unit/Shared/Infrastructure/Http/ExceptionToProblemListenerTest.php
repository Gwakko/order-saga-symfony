<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http;

use App\Ordering\Application\Exception\InvalidCursor;
use App\Ordering\Application\Exception\ProductNotFound;
use App\Ordering\Application\Exception\ProductUnavailable;
use App\Ordering\Domain\Exception\IllegalOrderTransition;
use App\Ordering\Domain\Exception\OrderNotCancellable;
use App\Ordering\Domain\Exception\OrderNotRefundable;
use App\Ordering\Domain\Exception\PriceChanged;
use App\Ordering\Domain\Exception\RefundWindowClosed;
use App\Ordering\Domain\OrderStatus;
use App\Payment\Application\Exception\PaymentDeclined;
use App\Shared\Application\Exception\ConcurrencyConflict;
use App\Shared\Application\Exception\InvalidInput;
use App\Shared\Application\Exception\OrderNotFound;
use App\Shared\Domain\Currency;
use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;
use App\Shared\Domain\Exception\CurrencyMismatch;
use App\Shared\Domain\Exception\MoneyOutOfRange;
use App\Shared\Domain\Exception\MoneyOverflow;
use App\Shared\Domain\Exception\NegativeMoney;
use App\Shared\Domain\Exception\NonPositiveMoney;
use App\Shared\Domain\Exception\UnknownCurrency;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Money;
use App\Shared\Infrastructure\Http\Exception\CustomerRequired;
use App\Shared\Infrastructure\Http\ExceptionToProblemListener;
use App\Shared\Infrastructure\Http\Serializer\MoneyOutputNormalizer;
use App\Shared\Infrastructure\Http\Serializer\ProblemDetailsNormalizer;
use App\Shared\Infrastructure\Idempotency\Exception\IdempotencyKeyInvalid;
use App\Shared\Infrastructure\Idempotency\Exception\IdempotencyKeyMissing;
use App\Shared\Infrastructure\Idempotency\Exception\IdempotencyKeyReused;
use App\Shared\Infrastructure\Idempotency\Exception\IdempotentRequestInProgress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * 02_use_cases.md section 6: the error code decides the HTTP status.
 */
#[Group('M5.1')]
final class ExceptionToProblemListenerTest extends TestCase
{
    private const string ORDER = '0190a5c4-0000-7000-8000-000000000001';
    private const string PRODUCT = '0190a5c4-0000-7000-8000-000000000003';

    /**
     * @return iterable<string, array{\Throwable, int, string}>
     */
    public static function mappedErrors(): iterable
    {
        $order = OrderId::fromString(self::ORDER);
        $product = ProductId::fromString(self::PRODUCT);

        yield 'customer required' => [new CustomerRequired(), 401, 'CUSTOMER_REQUIRED'];
        yield 'key missing' => [new IdempotencyKeyMissing(), 400, 'IDEMPOTENCY_KEY_MISSING'];
        yield 'key invalid' => [new IdempotencyKeyInvalid(), 400, 'IDEMPOTENCY_KEY_INVALID'];
        yield 'key reused' => [new IdempotencyKeyReused(), 422, 'IDEMPOTENCY_KEY_REUSED'];
        yield 'in progress' => [new IdempotentRequestInProgress(), 409, 'IDEMPOTENT_REQUEST_IN_PROGRESS'];
        yield 'negative money' => [new NegativeMoney(-1), 422, 'NEGATIVE_MONEY'];
        yield 'non-positive money' => [new NonPositiveMoney(0), 422, 'NON_POSITIVE_MONEY'];
        yield 'money out of range' => [new MoneyOutOfRange(1), 422, 'MONEY_OUT_OF_RANGE'];
        yield 'money overflow' => [new MoneyOverflow(), 422, 'MONEY_OVERFLOW'];
        yield 'unknown currency' => [new UnknownCurrency('XYZ'), 422, 'UNKNOWN_CURRENCY'];
        yield 'currency mismatch' => [new CurrencyMismatch(Currency::EUR, Currency::USD), 422, 'CURRENCY_MISMATCH'];
        yield 'price changed' => [new PriceChanged(Money::positive(1999, Currency::EUR)), 409, 'PRICE_CHANGED'];
        yield 'product not found' => [new ProductNotFound($product), 404, 'PRODUCT_NOT_FOUND'];
        yield 'product unavailable' => [new ProductUnavailable($product), 409, 'PRODUCT_UNAVAILABLE'];
        yield 'order not found' => [new OrderNotFound($order), 404, 'ORDER_NOT_FOUND'];
        yield 'illegal transition' => [new IllegalOrderTransition(OrderStatus::Paid, OrderStatus::Pending), 409, 'ILLEGAL_ORDER_TRANSITION'];
        yield 'not cancellable' => [new OrderNotCancellable(OrderStatus::Completed), 409, 'ORDER_NOT_CANCELLABLE'];
        yield 'not refundable' => [new OrderNotRefundable(OrderStatus::Pending), 409, 'ORDER_NOT_REFUNDABLE'];
        yield 'refund window closed' => [new RefundWindowClosed(), 409, 'REFUND_WINDOW_CLOSED'];
        yield 'concurrency conflict' => [new ConcurrencyConflict('Order', self::ORDER), 409, 'CONCURRENCY_CONFLICT'];
        yield 'invalid cursor' => [new InvalidCursor(), 422, 'INVALID_CURSOR'];
        yield 'invalid input' => [new InvalidInput('limit', 'must be 1..100'), 422, 'VALIDATION_FAILED'];
        yield 'http 404' => [new NotFoundHttpException('No route.'), 404, 'NOT_FOUND'];
    }

    #[DataProvider('mappedErrors')]
    public function testMapsErrorToStatusAndCode(\Throwable $error, int $status, string $code): void
    {
        $response = $this->handle($error);
        $body = $this->body($response);

        self::assertSame($status, $response->getStatusCode());
        self::assertSame($status, $body['status']);
        self::assertSame($code, $body['code']);
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame(Response::$statusTexts[$status], $body['title']);
    }

    public function testEveryDomainErrorCodeHasADeliberateStatus(): void
    {
        foreach (DomainErrorCode::cases() as $code) {
            $response = $this->handle(new class ($code) extends DomainError {
                public function __construct(private readonly DomainErrorCode $errorCode)
                {
                    parent::__construct('x');
                }

                public function code(): DomainErrorCode
                {
                    return $this->errorCode;
                }
            });

            self::assertContains($response->getStatusCode(), [400, 401, 404, 409, 422, 500], $code->value);
            self::assertSame($code->value, $this->body($response)['code']);
        }
    }

    public function testSagaInternalErrorIsAServerErrorAndHidesItsMessage(): void
    {
        $response = $this->handle(new PaymentDeclined(OrderId::fromString(self::ORDER), 'secret reason'));
        $body = $this->body($response);

        self::assertSame(500, $response->getStatusCode());
        self::assertArrayNotHasKey('detail', $body);
    }

    public function testUnexpectedExceptionIsAnOpaque500(): void
    {
        $response = $this->handle(new \RuntimeException('SQLSTATE[08006] password=hunter2'));
        $body = $this->body($response);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('INTERNAL_ERROR', $body['code']);
        self::assertArrayNotHasKey('detail', $body);
        self::assertStringNotContainsString('hunter2', (string) $response->getContent());
    }

    public function testInProgressAnswerAsksToRetryAfterOneSecond(): void
    {
        self::assertSame('1', $this->handle(new IdempotentRequestInProgress())->headers->get('Retry-After'));
        self::assertNull($this->handle(new IdempotencyKeyReused())->headers->get('Retry-After'));
    }

    public function testPriceChangeCarriesTheCurrentPrice(): void
    {
        $body = $this->body($this->handle(new PriceChanged(Money::positive(1999, Currency::EUR))));

        self::assertSame(['amount' => 1999, 'currency' => 'EUR'], $body['currentPrice']);
    }

    public function testValidationViolationsBecomeErrorsWithPropertyPaths(): void
    {
        $violations = new ConstraintViolationList([
            new ConstraintViolation('This value should be positive.', null, [], null, 'expectedPrice.amount', -1),
            new ConstraintViolation('This value is not a valid UUID.', null, [], null, 'productId', 'abc'),
        ]);
        $http = new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException('x', new ValidationFailedException(null, $violations));

        $response = $this->handle($http);
        $body = $this->body($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('VALIDATION_FAILED', $body['code']);
        self::assertSame([
            ['propertyPath' => 'expectedPrice.amount', 'message' => 'This value should be positive.'],
            ['propertyPath' => 'productId', 'message' => 'This value is not a valid UUID.'],
        ], $body['errors']);
    }

    public function testErrorsOutsideTheApiAreLeftToSymfony(): void
    {
        $event = new ExceptionEvent(self::createStub(HttpKernelInterface::class), Request::create('/_error/404'), HttpKernelInterface::MAIN_REQUEST, new NotFoundHttpException());
        $this->listener()($event);

        self::assertFalse($event->hasResponse());
    }

    private function handle(\Throwable $error): Response
    {
        $event = new ExceptionEvent(self::createStub(HttpKernelInterface::class), Request::create('/api/orders'), HttpKernelInterface::MAIN_REQUEST, $error);
        $this->listener()($event);

        return $event->getResponse() ?? throw new \LogicException('The listener set no response.');
    }

    private function listener(): ExceptionToProblemListener
    {
        return new ExceptionToProblemListener(
            new Serializer([new ProblemDetailsNormalizer(), new MoneyOutputNormalizer()], [new JsonEncoder()]),
            new NullLogger(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $body = [];

        foreach ($decoded as $key => $value) {
            $body[(string) $key] = $value;
        }

        return $body;
    }
}
