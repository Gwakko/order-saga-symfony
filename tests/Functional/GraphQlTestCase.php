<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Functional\Support\SqlRecorder;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared plumbing of the GraphQL functional tests: one POST to `/graphql`, decoded.
 */
abstract class GraphQlTestCase extends FunctionalTestCase
{
    /**
     * @param array<string, mixed> $variables
     * @param array<string, string> $headers
     */
    protected function graphql(string $query, ?string $customerId, array $variables = [], array $headers = []): Response
    {
        $payload = ['query' => $query];

        if ($variables !== []) {
            $payload['variables'] = $variables;
        }

        return $this->request('POST', '/graphql', $customerId, json_encode($payload, JSON_THROW_ON_ERROR), $headers);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function data(Response $response): array
    {
        $data = self::json($response)['data'] ?? null;
        self::assertIsArray($data, (string) $response->getContent());

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function firstError(Response $response): array
    {
        $errors = self::json($response)['errors'] ?? null;
        self::assertIsArray($errors, (string) $response->getContent());
        self::assertIsArray($errors[0] ?? null);

        /** @var array<string, mixed> $first */
        $first = $errors[0];

        return $first;
    }

    protected static function errorCode(Response $response): string
    {
        $extensions = self::firstError($response)['extensions'] ?? null;
        self::assertIsArray($extensions);

        return self::str($extensions['code'] ?? null);
    }

    protected function recorder(): SqlRecorder
    {
        $recorder = self::getContainer()->get(SqlRecorder::class);
        self::assertInstanceOf(SqlRecorder::class, $recorder);

        return $recorder;
    }

    protected function seedProductView(string $productId, string $name = 'Game', int $price = 1999, int $available = 3): void
    {
        $this->connection->executeStatement(
            'INSERT INTO product_availability_view (product_id, name, price_amount, price_currency, available, updated_at)
             VALUES (:id, :name, :price, \'EUR\', :available, now())',
            ['id' => $productId, 'name' => $name, 'price' => $price, 'available' => $available],
        );
    }
}
