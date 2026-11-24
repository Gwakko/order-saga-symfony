<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\GraphQL;

use App\Shared\Domain\DomainError;
use App\Shared\Infrastructure\Http\ErrorStatusMap;
use GraphQL\Error\Error;
use GraphQL\Language\SourceLocation;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Turns any failure into a GraphQL error whose `extensions.code` is the same stable code the REST problem carries
 * (`status` is the HTTP status REST answers with). A defect never leaks its message.
 */
final readonly class GraphQlErrorFormatter
{
    public const string VALIDATION_CODE = 'GRAPHQL_VALIDATION_FAILED';
    public const string INTERNAL_CODE = 'INTERNAL_ERROR';
    public const string INTERNAL_MESSAGE = 'Internal server error.';

    public function __construct(private LoggerInterface $logger) {}

    /**
     * @return array{message: string, locations?: array<int, array{line: int, column: int}>, path?: array<int, int|string>, extensions?: array<string, mixed>}
     */
    public function __invoke(\Throwable $failure): array
    {
        $described = $this->describe($failure);
        $formatted = [
            'message' => $described['message'],
            'extensions' => ['code' => $described['code'], 'status' => $described['status']],
        ];

        if (!$failure instanceof Error) {
            return $formatted;
        }

        $locations = array_map(static fn(SourceLocation $location): array => $location->toSerializableArray(), $failure->getLocations());

        if ($locations !== []) {
            $formatted['locations'] = $locations;
        }

        $path = $failure->getPath();

        if ($path !== null && $path !== []) {
            $formatted['path'] = $path;
        }

        return $formatted;
    }

    /**
     * @return array{code: string, status: int, message: string}
     */
    public function describe(\Throwable $failure): array
    {
        $domain = self::find($failure, DomainError::class);

        if ($domain !== null) {
            $status = ErrorStatusMap::statusOf($domain->code());

            return $status >= Response::HTTP_INTERNAL_SERVER_ERROR
                ? $this->internal($failure)
                : ['code' => $domain->code()->value, 'status' => $status, 'message' => $domain->getMessage()];
        }

        $http = self::find($failure, HttpExceptionInterface::class);

        if ($http !== null) {
            $status = $http->getStatusCode();

            return $status >= Response::HTTP_INTERNAL_SERVER_ERROR
                ? $this->internal($failure)
                : ['code' => ErrorStatusMap::httpCode($status), 'status' => $status, 'message' => $http->getMessage()];
        }

        // Parse, validation and variable errors raised by webonyx itself: safe to show, the client caused them.
        if ($failure instanceof Error && $failure->isClientSafe() && $failure->getPrevious() === null) {
            return ['code' => self::VALIDATION_CODE, 'status' => Response::HTTP_BAD_REQUEST, 'message' => $failure->getMessage()];
        }

        return $this->internal($failure);
    }

    /**
     * @return array{code: string, status: int, message: string}
     */
    private function internal(\Throwable $failure): array
    {
        $this->logger->error('GraphQL request failed with a server error.', ['exception' => $failure]);

        return ['code' => self::INTERNAL_CODE, 'status' => Response::HTTP_INTERNAL_SERVER_ERROR, 'message' => self::INTERNAL_MESSAGE];
    }

    /**
     * @template T of \Throwable
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private static function find(\Throwable $failure, string $class): ?\Throwable
    {
        for ($current = $failure; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof $class) {
                return $current;
            }
        }

        return null;
    }
}
