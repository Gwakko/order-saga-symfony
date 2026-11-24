<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * EC-71: the caller is identified by `X-Customer-Id`; without a valid one nothing runs.
 */
#[Group('M5.1')]
final class CustomerRequiredTest extends FunctionalTestCase
{
    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function endpoints(): iterable
    {
        $id = '0190a5c4-0000-7000-8000-000000000001';

        foreach (['absent' => null, 'not a UUID' => 'abc', 'empty' => '', 'UUID with junk' => '0190a5c4-0000-7000-8000-000000000001x', 'braces' => '{0190a5c4-0000-7000-8000-000000000001}'] as $label => $header) {
            yield 'place order, header ' . $label => ['POST', '/api/orders', $header];
            yield 'cancel, header ' . $label => ['POST', sprintf('/api/orders/%s/cancel', $id), $header];
            yield 'refund, header ' . $label => ['POST', sprintf('/api/orders/%s/refund', $id), $header];
            yield 'get order, header ' . $label => ['GET', '/api/orders/' . $id, $header];
            yield 'list orders, header ' . $label => ['GET', '/api/orders', $header];
        }
    }

    #[Group('T-F-25')]
    #[DataProvider('endpoints')]
    public function testMissingOrInvalidCustomerIsUnauthorized(string $method, string $uri, ?string $header): void
    {
        $product = $this->seedProduct();
        $body = $method === 'POST' && $uri === '/api/orders' ? json_encode(self::orderBody($product), JSON_THROW_ON_ERROR) : '';

        $response = $this->request($method, $uri, $header, $body, ['Idempotency-Key' => 'key-1']);

        self::assertSame(401, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('CUSTOMER_REQUIRED', self::json($response)['code']);
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame(0, $this->countRows('orders'));
        self::assertSame(0, $this->countRows('idempotency_keys'), 'no key is registered for an unknown caller');
    }

    #[Group('T-F-25')]
    public function testCustomerIsCheckedBeforeTheIdempotencyKey(): void
    {
        $response = $this->placeOrder(null, self::orderBody(self::uuid()), null);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Group('T-F-25')]
    public function testUpperCaseHeaderIsAccepted(): void
    {
        $product = $this->seedProduct();
        $customer = self::uuid();

        $created = $this->placeOrder(strtoupper($customer), self::orderBody($product));

        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        self::assertSame(1, $this->countRows('orders', sprintf("customer_id = '%s'", $customer)));
    }
}
