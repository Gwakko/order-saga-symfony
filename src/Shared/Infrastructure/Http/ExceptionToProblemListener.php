<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Ordering\Domain\Exception\PriceChanged;
use App\Shared\Application\Output\MoneyOutput;
use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;
use App\Shared\Infrastructure\Http\Problem\ProblemDetails;
use App\Shared\Infrastructure\Http\Problem\ProblemViolation;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Every error of an `/api` request leaves as RFC 9457 `application/problem+json` (02_use_cases.md section 6).
 * The code-to-status table is one exhaustive `match`: a new error code does not compile into the table by accident.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 0)]
final readonly class ExceptionToProblemListener
{
    public function __construct(private SerializerInterface $serializer, private LoggerInterface $logger) {}

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return;
        }

        $exception = $event->getThrowable();
        $problem = $this->describe($exception);

        if ($problem->status >= Response::HTTP_INTERNAL_SERVER_ERROR) {
            $this->logger->error('Request failed with a server error.', ['exception' => $exception]);
        }

        $response = new JsonResponse($this->serializer->serialize($problem, 'json'), $problem->status, [], true);
        $response->headers->set('Content-Type', ProblemDetails::MEDIA_TYPE);

        if ($problem->code === DomainErrorCode::IdempotentRequestInProgress->value) {
            $response->headers->set('Retry-After', '1');
        }

        $event->setResponse($response);
    }

    private function describe(\Throwable $exception): ProblemDetails
    {
        $validation = self::find($exception, ValidationFailedException::class);

        if ($validation !== null) {
            return self::problem(Response::HTTP_UNPROCESSABLE_ENTITY, DomainErrorCode::ValidationFailed->value, 'The request is invalid.', self::violations($validation));
        }

        // `allow_extra_attributes: false` throws this directly (it is not collected as a violation by the payload resolver).
        $extra = self::find($exception, ExtraAttributesException::class);

        if ($extra !== null) {
            return self::problem(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                DomainErrorCode::ValidationFailed->value,
                'The request is invalid.',
                array_map(static fn(string $attribute): ProblemViolation => new ProblemViolation($attribute, 'This field is not expected.'), $extra->getExtraAttributes()),
            );
        }

        $domain = self::find($exception, DomainError::class);

        if ($domain !== null) {
            return self::fromDomainError($domain);
        }

        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();

            return self::problem($status, ErrorStatusMap::httpCode($status), $status >= Response::HTTP_INTERNAL_SERVER_ERROR ? null : $exception->getMessage());
        }

        // Never leak internals of an unexpected failure.
        return self::problem(Response::HTTP_INTERNAL_SERVER_ERROR, 'INTERNAL_ERROR');
    }

    private static function fromDomainError(DomainError $error): ProblemDetails
    {
        $code = $error->code();
        $status = ErrorStatusMap::statusOf($code);
        $detail = $status >= Response::HTTP_INTERNAL_SERVER_ERROR ? null : $error->getMessage();
        $currentPrice = $error instanceof PriceChanged ? MoneyOutput::fromMoney($error->current) : null;

        return self::problem($status, $code->value, $detail, [], $currentPrice);
    }

    /**
     * @param list<ProblemViolation> $errors
     */
    private static function problem(int $status, string $code, ?string $detail = null, array $errors = [], ?MoneyOutput $currentPrice = null): ProblemDetails
    {
        return new ProblemDetails(
            '/problems/' . strtolower(str_replace('_', '-', $code)),
            Response::$statusTexts[$status] ?? 'Error',
            $status,
            $code,
            $detail,
            $errors,
            $currentPrice,
        );
    }

    /**
     * @return list<ProblemViolation>
     */
    private static function violations(ValidationFailedException $exception): array
    {
        $violations = [];

        /** @var ConstraintViolationInterface $violation */
        foreach ($exception->getViolations() as $violation) {
            $violations[] = new ProblemViolation($violation->getPropertyPath(), (string) $violation->getMessage());
        }

        return $violations;
    }

    /**
     * @template T of \Throwable
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private static function find(\Throwable $exception, string $class): ?\Throwable
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof $class) {
                return $current;
            }
        }

        return null;
    }
}
