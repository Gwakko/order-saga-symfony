<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;

/**
 * Malformed input never reaches the domain: 4xx with a Problem Details body, no order, no outbox event.
 */
#[Group('M5.1')]
final class PlaceOrderValidationTest extends FunctionalTestCase
{
    private const string PRODUCT = '0190a5c4-0000-7000-8000-0000000000f1';

    /**
     * @return iterable<string, array{string, int, ?string, string}>
     */
    public static function invalidBodies(): iterable
    {
        $product = self::PRODUCT;
        $price = static fn(string $amount, string $currency = '"EUR"'): string => sprintf('{"productId":"%s","expectedPrice":{"amount":%s,"currency":%s}}', $product, $amount, $currency);

        yield 'EC-64a negative amount' => [$price('-100'), 422, 'expectedPrice.amount', 'VALIDATION_FAILED'];
        yield 'EC-64b zero amount' => [$price('0'), 422, 'expectedPrice.amount', 'VALIDATION_FAILED'];
        yield 'EC-64c unknown currency' => [$price('100', '"XYZ"'), 422, 'expectedPrice.currency', 'VALIDATION_FAILED'];
        yield 'EC-64d lower-case currency' => [$price('100', '"eur"'), 422, 'expectedPrice.currency', 'VALIDATION_FAILED'];
        yield 'EC-64e amount as string' => [$price('"100"'), 422, 'expectedPrice.amount', 'VALIDATION_FAILED'];
        yield 'EC-64f fractional amount' => [$price('10.5'), 422, 'expectedPrice.amount', 'VALIDATION_FAILED'];
        yield 'EC-64g above the maximum' => [$price('100000001'), 422, 'expectedPrice.amount', 'VALIDATION_FAILED'];
        yield 'EC-64h beyond PHP_INT_MAX' => [$price('99999999999999999999'), 422, 'expectedPrice.amount', 'VALIDATION_FAILED'];
        yield 'EC-64i extra field' => [sprintf('{"productId":"%s","price":1,"expectedPrice":{"amount":100,"currency":"EUR"}}', $product), 422, 'price', 'VALIDATION_FAILED'];
        yield 'EC-64j broken JSON' => ['{"productId":', 400, null, 'BAD_REQUEST'];
        yield 'EC-64k productId is not a UUID' => ['{"productId":"abc","expectedPrice":{"amount":100,"currency":"EUR"}}', 422, 'productId', 'VALIDATION_FAILED'];

        // Beyond the spec table: what the same mapping does with other malformed bodies.
        yield 'whole number written as float' => [$price('100.0'), 422, 'expectedPrice.amount', 'VALIDATION_FAILED'];
        yield 'exponent notation' => [$price('1e2'), 422, 'expectedPrice.amount', 'VALIDATION_FAILED'];
        yield 'amount null' => [$price('null'), 422, 'expectedPrice.amount', 'VALIDATION_FAILED'];
        yield 'amount boolean' => [$price('true'), 422, 'expectedPrice.amount', 'VALIDATION_FAILED'];
        yield 'currency number' => [$price('100', '5'), 422, 'expectedPrice.currency', 'VALIDATION_FAILED'];
        yield 'currency empty' => [$price('100', '""'), 422, 'expectedPrice.currency', 'VALIDATION_FAILED'];
        yield 'productId number' => ['{"productId":5,"expectedPrice":{"amount":100,"currency":"EUR"}}', 422, 'productId', 'VALIDATION_FAILED'];
        yield 'productId missing' => ['{"expectedPrice":{"amount":100,"currency":"EUR"}}', 422, 'productId', 'VALIDATION_FAILED'];
        yield 'expectedPrice missing' => [sprintf('{"productId":"%s"}', $product), 422, 'expectedPrice', 'VALIDATION_FAILED'];
        yield 'expectedPrice null' => [sprintf('{"productId":"%s","expectedPrice":null}', $product), 422, 'expectedPrice.amount', 'VALIDATION_FAILED'];
        yield 'expectedPrice is a number' => [sprintf('{"productId":"%s","expectedPrice":5}', $product), 422, 'expectedPrice.0', 'VALIDATION_FAILED'];
        yield 'extra field inside expectedPrice' => [$price('100', '"EUR","scale":2'), 422, 'expectedPrice.scale', 'VALIDATION_FAILED'];
        yield 'amount missing' => [sprintf('{"productId":"%s","expectedPrice":{"currency":"EUR"}}', $product), 422, 'expectedPrice.amount', 'VALIDATION_FAILED'];
        yield 'empty object' => ['{}', 422, null, 'VALIDATION_FAILED'];
        yield 'JSON array' => ['[]', 422, null, 'VALIDATION_FAILED'];
        yield 'JSON null' => ['null', 422, null, 'VALIDATION_FAILED'];
        yield 'JSON string' => ['"text"', 422, '0', 'VALIDATION_FAILED'];
        yield 'empty body' => ['', 400, null, 'BAD_REQUEST'];
    }

    #[Group('T-F-20')]
    #[DataProvider('invalidBodies')]
    // Symfony's payload resolver builds the DTO with the raw value after it has recorded the type error, and PHP
    // reports the lossy float-to-int coercion of that constructor call (EC-64f). The answer is still 422.
    #[IgnoreDeprecations('Implicit conversion from float')]
    public function testInvalidInputIsRejected(string $body, int $status, ?string $propertyPath, string $code): void
    {
        $this->seedProductWithId(self::PRODUCT);

        $response = $this->request('POST', '/api/orders', self::uuid(), $body, ['Idempotency-Key' => 'key-1']);
        $problem = self::json($response);

        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame($code, $problem['code']);
        self::assertSame($status, $problem['status']);
        self::assertSame(0, $this->countRows('orders'));
        self::assertSame(0, $this->countRows('outbox_messages'));

        if ($propertyPath !== null) {
            self::assertContains($propertyPath, $this->paths($problem), 'errors[].propertyPath');
        }
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function otherContentTypes(): iterable
    {
        yield 'text/plain' => ['text/plain', 415, 'UNSUPPORTED_MEDIA_TYPE'];
        yield 'text/xml' => ['text/xml', 400, 'BAD_REQUEST'];
        yield 'form' => ['application/x-www-form-urlencoded', 400, 'BAD_REQUEST'];
        yield 'multipart' => ['multipart/form-data', 400, 'BAD_REQUEST'];
        yield 'unknown type' => ['application/x-nonsense', 415, 'UNSUPPORTED_MEDIA_TYPE'];
    }

    /**
     * A JSON body sent under another Content-Type is never decoded as JSON: 415 when the type is unknown or has no
     * serializer format, 400 when Symfony tries to parse it as that format and fails.
     */
    #[Group('T-F-20')]
    #[DataProvider('otherContentTypes')]
    public function testOtherContentTypeIsRejected(string $contentType, int $status, string $code): void
    {
        $this->seedProductWithId(self::PRODUCT);
        $body = json_encode(self::orderBody(self::PRODUCT), JSON_THROW_ON_ERROR);

        $this->client->request('POST', '/api/orders', [], [], [
            'CONTENT_TYPE' => $contentType,
            'HTTP_X_CUSTOMER_ID' => self::uuid(),
            'HTTP_IDEMPOTENCY_KEY' => 'key-1',
        ], $body);
        $response = $this->client->getResponse();

        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame($code, self::json($response)['code']);
        self::assertSame(0, $this->countRows('orders'));
    }

    #[Group('T-F-20')]
    public function testMissingContentTypeIsRejected(): void
    {
        $this->seedProductWithId(self::PRODUCT);

        $this->client->request('POST', '/api/orders', [], [], [
            'HTTP_X_CUSTOMER_ID' => self::uuid(),
            'HTTP_IDEMPOTENCY_KEY' => 'key-1',
        ], json_encode(self::orderBody(self::PRODUCT), JSON_THROW_ON_ERROR));
        $response = $this->client->getResponse();

        // Without a Content-Type the client sends the body as `application/x-www-form-urlencoded` (the default of HTTP clients).
        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(0, $this->countRows('orders'));
    }

    /**
     * @param array<string, mixed> $problem
     *
     * @return list<string>
     */
    private function paths(array $problem): array
    {
        $errors = $problem['errors'] ?? [];
        self::assertIsArray($errors);

        return array_values(array_map(
            static fn(mixed $error): string => is_array($error) && is_string($error['propertyPath'] ?? null) ? $error['propertyPath'] : '',
            $errors,
        ));
    }

    private function seedProductWithId(string $id): void
    {
        $this->connection->executeStatement(
            "INSERT INTO products (id, name, price_amount, price_currency, active) VALUES (:id, 'Game', 1999, 'EUR', TRUE)",
            ['id' => $id],
        );
    }
}
