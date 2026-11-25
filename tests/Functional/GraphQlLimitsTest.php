<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use GraphQL\Type\Introspection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * EC-82: depth 8, complexity 200; a rejected query never reaches a resolver.
 */
#[Group('M7.2')]
final class GraphQlLimitsTest extends GraphQlTestCase
{
    /**
     * The schema has no recursive type, so a deep regular query can only be built from fields that do not exist: the
     * request is refused by validation (400) and the depth error is among the errors. The exact boundary (8 passes, 9
     * fails) is covered with a recursive schema in GraphQlValidationRulesTest (T-M8A-01).
     */
    private static function nonexistentChain(int $levels): string
    {
        $inner = '{ name }';

        for ($i = 0; $i < $levels; ++$i) {
            $inner = sprintf('{ a %s }', $inner);
        }

        return sprintf('{ myOrders %s }', $inner);
    }

    private static function aliases(int $count, string $selection): string
    {
        $fields = [];

        for ($i = 0; $i < $count; ++$i) {
            $fields[] = sprintf('a%d: %s', $i, $selection);
        }

        return '{ ' . implode(' ', $fields) . ' }';
    }

    #[Group('T-F-30')]
    public function testQueryDeeperThanTheLimitIsRejectedBeforeAnyResolver(): void
    {
        $this->recorder()->reset();
        $response = $this->graphql(self::nonexistentChain(9), self::uuid());
        $body = self::json($response);
        $messages = implode("\n", array_map(static fn(mixed $e): string => is_array($e) ? self::str($e['message'] ?? null) : '', (array) ($body['errors'] ?? [])));

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayNotHasKey('data', $body);
        self::assertSame('GRAPHQL_VALIDATION_FAILED', self::errorCode($response));
        self::assertStringContainsString('Max query depth should be 8 but got 9', $messages);
        self::assertSame([], $this->recorder()->statements());
    }

    #[Group('T-M8A-01')]
    public function testDeepIntrospectionQueryRunsOutsideProduction(): void
    {
        // ofType nested 7 times: depth 11 for the old uniform limit, ignored now that introspection is on.
        $inner = '{ name }';

        for ($i = 0; $i < 7; ++$i) {
            $inner = sprintf('{ ofType %s }', $inner);
        }

        $response = $this->graphql(sprintf('{ __type(name: "Query") { fields { type %s } } }', $inner), self::uuid());

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertArrayNotHasKey('errors', self::json($response));
    }

    #[Group('T-F-30')]
    public function testQueryWithinTheComplexityLimitRuns(): void
    {
        $response = $this->graphql(self::aliases(40, 'myOrders { items { orderId status } }'), self::uuid());

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertArrayNotHasKey('errors', self::json($response));
    }

    #[Group('T-F-30')]
    public function testQueryMoreComplexThanTheLimitIsRejectedBeforeAnyResolver(): void
    {
        $this->recorder()->reset();
        $response = $this->graphql(self::aliases(60, 'myOrders { items { orderId status } }'), self::uuid());

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayNotHasKey('data', self::json($response));
        self::assertSame('GRAPHQL_VALIDATION_FAILED', self::errorCode($response));
        self::assertStringContainsString('Max query complexity should be 200', self::str(self::firstError($response)['message']));
        self::assertSame([], $this->recorder()->statements(), 'no resolver ran');
    }

    #[Group('T-F-30')]
    public function testBigPagesCostMoreThanSmallOnes(): void
    {
        $query = static fn(int $limit): string => self::aliases(10, sprintf('myOrders(limit: %d) { items { orderId product { name } } }', $limit));

        $small = $this->graphql($query(20), self::uuid());
        $big = $this->graphql($query(100), self::uuid());

        self::assertSame(200, $small->getStatusCode(), (string) $small->getContent());
        self::assertSame(400, $big->getStatusCode());
        self::assertStringContainsString('Max query complexity should be 200', self::str(self::firstError($big)['message']));
    }

    #[Group('T-F-30')]
    public function testIntrospectionIsOnOutsideProduction(): void
    {
        $response = $this->graphql('{ __schema { queryType { name } } }', self::uuid());

        self::assertSame(['__schema' => ['queryType' => ['name' => 'Query']]], self::data($response));
    }

    /**
     * M8a: the full query of GraphiQL (depth 11 by plain counting) runs where introspection is on.
     */
    #[Group('T-M8A-01')]
    public function testTheFullStandardIntrospectionQueryRunsOutsideProduction(): void
    {
        $response = $this->graphql(Introspection::getIntrospectionQuery(), self::uuid());

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertArrayNotHasKey('errors', self::json($response));
        self::assertArrayHasKey('__schema', self::data($response));
    }
}
