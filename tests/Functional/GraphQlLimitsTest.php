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
     * The schema has no recursive type, so introspection (`ofType` nests without bound) is the way to build depth.
     */
    private static function nested(int $ofTypeLevels): string
    {
        $inner = '{ name }';

        for ($i = 0; $i < $ofTypeLevels; ++$i) {
            $inner = sprintf('{ ofType %s }', $inner);
        }

        return sprintf('{ __type(name: "Query") { fields { type %s } } }', $inner);
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
    public function testQueryAtTheDepthLimitRuns(): void
    {
        // __type (0) > fields (1) > type (2) > six ofType = depth 8.
        $response = $this->graphql(self::nested(6), self::uuid());

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertArrayNotHasKey('errors', self::json($response));
    }

    #[Group('T-F-30')]
    public function testQueryDeeperThanTheLimitIsRejectedBeforeAnyResolver(): void
    {
        $this->recorder()->reset();
        $response = $this->graphql(self::nested(7), self::uuid());
        $body = self::json($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayNotHasKey('data', $body);
        self::assertSame('GRAPHQL_VALIDATION_FAILED', self::errorCode($response));
        self::assertStringContainsString('Max query depth should be 8 but got 9', self::str(self::firstError($response)['message']));
        self::assertSame([], $this->recorder()->statements());
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
     * Known consequence of one uniform depth limit: the full query of GraphiQL (`TypeRef` nests seven `ofType`) is
     * depth 11. Tools that send smaller queries, and `__type`/`__schema` lookups by hand, work.
     */
    public function testTheFullStandardIntrospectionQueryExceedsTheDepthLimit(): void
    {
        $response = $this->graphql(Introspection::getIntrospectionQuery(), self::uuid());

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('Max query depth should be 8 but got 11', self::str(self::firstError($response)['message']));
    }
}
