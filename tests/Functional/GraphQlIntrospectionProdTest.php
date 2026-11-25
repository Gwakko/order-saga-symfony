<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Kernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EC-82 / M7.2: the production kernel answers no introspection. Introspection does not touch the database, so this
 * test boots the real `prod` container (the test client only exists in `test`) and needs no tables.
 */
#[Group('M7.2')]
#[Group('T-F-30')]
final class GraphQlIntrospectionProdTest extends TestCase
{
    public function testIntrospectionIsRefusedInProduction(): void
    {
        foreach (['{ __schema { queryType { name } } }', '{ __type(name: "Query") { name } }'] as $query) {
            $response = self::post($query);
            $body = (string) $response->getContent();

            self::assertSame(400, $response->getStatusCode(), $body);
            self::assertStringContainsString('introspection is not allowed', strtolower($body));
            self::assertStringNotContainsString('"data"', $body);
        }
    }

    #[Group('T-M8A-01')]
    public function testFullIntrospectionQueryIsRefusedInProductionWithTheDepthLimitKept(): void
    {
        $response = self::post(\GraphQL\Type\Introspection::getIntrospectionQuery());
        $body = strtolower((string) $response->getContent());

        // Actual behaviour of the real prod kernel: 400, validation errors only, no data; both the introspection and
        // the depth (11 > 8: the exemption is off in prod) errors are listed.
        self::assertSame(400, $response->getStatusCode(), $body);
        self::assertStringContainsString('introspection is not allowed', $body);
        self::assertStringContainsString('max query depth should be 8', $body);
        self::assertStringNotContainsString('"data"', $body);
    }

    public function testRegularQueriesStillValidateInProduction(): void
    {
        $response = self::post('{ __typename }');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    private static function post(string $query): Response
    {
        $kernel = new Kernel('prod', false);

        try {
            return $kernel->handle(Request::create('/graphql', 'POST', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CUSTOMER_ID' => '0190a5c4-0000-7000-8000-000000000001',
            ], json_encode(['query' => $query], JSON_THROW_ON_ERROR)));
        } finally {
            $kernel->shutdown();
        }
    }
}
