<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\GraphQL;

use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Infrastructure\Bus\QueryBus;
use GraphQL\Error\Error;
use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The only GraphQL entry point (read side only, U2). The caller comes from `X-Customer-Id` through the same argument
 * resolver as the REST controllers.
 */
#[Route('/graphql', name: 'graphql', methods: ['POST'])]
final readonly class GraphQlController
{
    public const int MAX_BODY_BYTES = 16_384;

    public function __construct(
        private Schema $schema,
        private QueryBus $queries,
        private GraphQlValidationRules $rules,
        private GraphQlErrorFormatter $errors,
    ) {}

    public function __invoke(CustomerId $customerId, Request $request): Response
    {
        [$query, $variables, $operationName] = self::parse($request);

        $result = GraphQL::executeQuery(
            $this->schema,
            $query,
            null,
            new GraphQlContext($customerId, new ProductBatchLoader($this->queries)),
            $variables,
            $operationName,
            null,
            $this->rules->create(),
        )->setErrorFormatter($this->errors);

        // Parse and validation failures (no query ran) are a client error; failures inside a field keep GraphQL's 200.
        $status = $result->data === null && $result->errors !== [] && self::allWithoutCause($result->errors)
            ? Response::HTTP_BAD_REQUEST
            : Response::HTTP_OK;

        return new JsonResponse($result->toArray(), $status);
    }

    /**
     * @return array{string, array<string, mixed>|null, string|null}
     */
    private static function parse(Request $request): array
    {
        $length = $request->headers->get('Content-Length');
        $body = $request->getContent();

        if (strlen($body) > self::MAX_BODY_BYTES || ($length !== null && (int) $length > self::MAX_BODY_BYTES)) {
            throw new HttpException(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, sprintf('The request body is limited to %d bytes.', self::MAX_BODY_BYTES));
        }

        try {
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('The body must be a JSON object.');
        }

        if (!is_array($decoded) || !is_string($decoded['query'] ?? null)) {
            throw new BadRequestHttpException('The body must be a JSON object with a string "query".');
        }

        $variables = $decoded['variables'] ?? null;
        $operationName = $decoded['operationName'] ?? null;

        if (($variables !== null && !is_array($variables)) || ($operationName !== null && !is_string($operationName))) {
            throw new BadRequestHttpException('"variables" must be an object and "operationName" a string.');
        }

        /** @var array<string, mixed>|null $variables */
        return [$decoded['query'], $variables, $operationName];
    }

    /**
     * @param list<Error> $errors
     */
    private static function allWithoutCause(array $errors): bool
    {
        foreach ($errors as $error) {
            if ($error->getPrevious() !== null) {
                return false;
            }
        }

        return true;
    }
}
