<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Infrastructure\Idempotency\IdempotencyKey;
use App\Shared\Infrastructure\Idempotency\IdempotencyStore;
use App\Shared\Infrastructure\Idempotency\RequestFingerprint;
use App\Tests\Functional\Support\SwitchableProductCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * `POST /api/orders` with an `Idempotency-Key` (02_use_cases.md section 5, EC-01..EC-08).
 */
#[Group('M5.2')]
final class PlaceOrderIdempotencyTest extends FunctionalTestCase
{
    #[Group('T-F-01')]
    public function testSameKeySameBodyReplaysResponse(): void
    {
        $product = $this->seedProduct();
        $customer = self::uuid();

        $first = $this->placeOrder($customer, self::orderBody($product), 'abc-123');
        $second = $this->placeOrder($customer, self::orderBody($product), 'abc-123');

        self::assertSame(201, $first->getStatusCode(), (string) $first->getContent());
        self::assertFalse($first->headers->has('Idempotent-Replayed'));
        self::assertSame(201, $second->getStatusCode());
        self::assertSame('true', $second->headers->get('Idempotent-Replayed'));
        // jsonb stores the body as an object: key order may differ, the content may not.
        self::assertEquals(self::json($first), self::json($second), 'same status, same body, same orderId');
        self::assertSame('application/json', $second->headers->get('Content-Type'));
        self::assertSame(1, $this->countRows('orders'));
        self::assertSame(1, $this->countRows('outbox_messages', "event_type = 'OrderPlaced'"));
        self::assertSame(1, $this->countRows('idempotency_keys', "status = 'completed' AND response_status = 201"));
    }

    public function testBodyKeyOrderAndWhitespaceDoNotChangeTheRequest(): void
    {
        $product = $this->seedProduct();
        $customer = self::uuid();
        $compact = sprintf('{"productId":"%s","expectedPrice":{"amount":1999,"currency":"EUR"}}', $product);
        $reordered = sprintf('{ "expectedPrice": { "currency": "EUR", "amount": 1999 }, "productId": "%s" }', $product);

        $first = $this->request('POST', '/api/orders', $customer, $compact, ['Idempotency-Key' => 'k1']);
        $second = $this->request('POST', '/api/orders', $customer, $reordered, ['Idempotency-Key' => 'k1']);

        self::assertSame(201, $first->getStatusCode());
        self::assertSame('true', $second->headers->get('Idempotent-Replayed'));
        self::assertSame(1, $this->countRows('orders'));
    }

    #[Group('T-F-02')]
    public function testSameKeyDifferentBodyRejected(): void
    {
        $productA = $this->seedProduct();
        $productB = $this->seedProduct();
        $customer = self::uuid();

        $this->placeOrder($customer, self::orderBody($productA), 'abc-123');
        $second = $this->placeOrder($customer, self::orderBody($productB), 'abc-123');

        self::assertSame(422, $second->getStatusCode(), (string) $second->getContent());
        self::assertSame('IDEMPOTENCY_KEY_REUSED', self::json($second)['code']);
        self::assertFalse($second->headers->has('Idempotent-Replayed'));
        self::assertSame(1, $this->countRows('orders'));
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function badKeys(): iterable
    {
        yield 'missing header' => [null, 'IDEMPOTENCY_KEY_MISSING'];
        yield 'empty' => ['', 'IDEMPOTENCY_KEY_INVALID'];
        yield 'too long (256)' => [str_repeat('a', 256), 'IDEMPOTENCY_KEY_INVALID'];
        yield 'slash' => ['abc/def', 'IDEMPOTENCY_KEY_INVALID'];
        yield 'space' => ['abc def', 'IDEMPOTENCY_KEY_INVALID'];
        yield 'dot' => ['abc.def', 'IDEMPOTENCY_KEY_INVALID'];
        yield 'non-ASCII letter' => ['ключ', 'IDEMPOTENCY_KEY_INVALID'];
        yield 'trailing newline' => ["abc\n", 'IDEMPOTENCY_KEY_INVALID'];
    }

    #[Group('T-F-03')]
    #[DataProvider('badKeys')]
    public function testMissingOrInvalidKeyIsRejected(?string $key, string $code): void
    {
        $product = $this->seedProduct();

        $response = $this->placeOrder(self::uuid(), self::orderBody($product), $key);

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame($code, self::json($response)['code']);
        self::assertSame(0, $this->countRows('orders'), 'the handler was not called');
        self::assertSame(0, $this->countRows('idempotency_keys'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function goodKeys(): iterable
    {
        yield 'one character' => ['a'];
        yield '255 characters' => [str_repeat('a', 255)];
        yield 'all allowed symbols' => ['Az09_-'];
        yield 'uuid' => ['0190a5c4-0000-7000-8000-000000000001'];
    }

    #[DataProvider('goodKeys')]
    public function testKeysWithinTheContractAreAccepted(string $key): void
    {
        $product = $this->seedProduct();

        $response = $this->placeOrder(self::uuid(), self::orderBody($product), $key);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Group('T-F-04')]
    public function testSameKeyForDifferentCustomersIsIndependent(): void
    {
        $product = $this->seedProduct();
        $first = $this->placeOrder(self::uuid(), self::orderBody($product), 'shared-key');
        $second = $this->placeOrder(self::uuid(), self::orderBody($product), 'shared-key');

        self::assertSame(201, $first->getStatusCode());
        self::assertSame(201, $second->getStatusCode(), (string) $second->getContent());
        self::assertFalse($second->headers->has('Idempotent-Replayed'));
        self::assertNotSame(self::json($first)['orderId'], self::json($second)['orderId']);
        self::assertSame(2, $this->countRows('orders'));
    }

    /**
     * EC-07: an unexpected failure ends as 500, the key is forgotten and the same key runs again.
     */
    #[Group('T-F-05')]
    public function testServerErrorReleasesTheKey(): void
    {
        $product = $this->seedProduct();
        $customer = self::uuid();
        $catalog = self::getContainer()->get(SwitchableProductCatalog::class);
        self::assertInstanceOf(SwitchableProductCatalog::class, $catalog);

        $catalog->failing = true;
        $failed = $this->placeOrder($customer, self::orderBody($product), 'retry-me');

        self::assertSame(500, $failed->getStatusCode());
        self::assertSame('INTERNAL_ERROR', self::json($failed)['code']);
        self::assertStringNotContainsString('exploded', (string) $failed->getContent(), 'internals are not leaked');
        self::assertSame(0, $this->countRows('idempotency_keys'), 'the key was deleted');
        self::assertSame(0, $this->countRows('orders'));

        $catalog->failing = false;
        $retried = $this->placeOrder($customer, self::orderBody($product), 'retry-me');

        self::assertSame(201, $retried->getStatusCode(), (string) $retried->getContent());
        self::assertFalse($retried->headers->has('Idempotent-Replayed'));
        self::assertSame(1, $this->countRows('orders'));
    }

    /**
     * EC-08: a business error is a stored answer; it is replayed, not recomputed.
     */
    #[Group('T-F-06')]
    public function testStoredBusinessErrorIsReplayed(): void
    {
        $product = $this->seedProduct(1999);
        $customer = self::uuid();

        $first = $this->placeOrder($customer, self::orderBody($product, 1899), 'price-key');
        self::assertSame(409, $first->getStatusCode());

        // The catalog now matches the client's expectation; the replay must still be the original 409.
        $this->connection->executeStatement('UPDATE products SET price_amount = 1899 WHERE id = :id', ['id' => $product]);
        $second = $this->placeOrder($customer, self::orderBody($product, 1899), 'price-key');

        self::assertSame(409, $second->getStatusCode(), (string) $second->getContent());
        self::assertSame('true', $second->headers->get('Idempotent-Replayed'));
        self::assertSame('application/problem+json', $second->headers->get('Content-Type'));
        self::assertEquals(self::json($first), self::json($second));
        self::assertSame('PRICE_CHANGED', self::json($second)['code']);
        self::assertSame(0, $this->countRows('orders'));
    }

    public function testValidationErrorIsStoredToo(): void
    {
        $customer = self::uuid();
        $body = '{"productId":"abc","expectedPrice":{"amount":1,"currency":"EUR"}}';

        $first = $this->request('POST', '/api/orders', $customer, $body, ['Idempotency-Key' => 'v1']);
        $second = $this->request('POST', '/api/orders', $customer, $body, ['Idempotency-Key' => 'v1']);

        self::assertSame(422, $first->getStatusCode());
        self::assertSame(422, $second->getStatusCode());
        self::assertSame('true', $second->headers->get('Idempotent-Replayed'));
    }

    /**
     * EC-02 seen over HTTP: while the first request is `in_progress` a duplicate gets 409 and `Retry-After`.
     */
    #[Group('EC-02')]
    public function testDuplicateWhileInProgressIsRefused(): void
    {
        $product = $this->seedProduct();
        $customer = self::uuid();
        $body = self::orderBody($product);
        $this->startedElsewhere($customer, 'busy', $body);

        $response = $this->placeOrder($customer, $body, 'busy');

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('IDEMPOTENT_REQUEST_IN_PROGRESS', self::json($response)['code']);
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertSame(0, $this->countRows('orders'));
        self::assertSame(1, $this->countRows('idempotency_keys', "status = 'in_progress'"), 'the first request keeps its row');
    }

    public function testInProgressWithOtherBodyIsAReuseNotAWait(): void
    {
        $customer = self::uuid();
        $this->startedElsewhere($customer, 'busy', self::orderBody(self::uuid()));

        $response = $this->placeOrder($customer, self::orderBody(self::uuid()), 'busy');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('IDEMPOTENCY_KEY_REUSED', self::json($response)['code']);
    }

    /**
     * EC-07b: a request that died leaves `in_progress` behind; after 60 s the key is free again.
     */
    public function testStaleInProgressDoesNotBlockTheKey(): void
    {
        $product = $this->seedProduct();
        $customer = self::uuid();
        $body = self::orderBody($product);
        $this->startedElsewhere($customer, 'crashed', $body);
        $this->connection->executeStatement("UPDATE idempotency_keys SET created_at = now() - interval '61 seconds'");

        $response = $this->placeOrder($customer, $body, 'crashed');

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(1, $this->countRows('orders'));
        self::assertSame(1, $this->countRows('idempotency_keys', "status = 'completed'"));
    }

    /**
     * EC-06 seen over HTTP: after the 24 h TTL the same key is a new request.
     */
    public function testExpiredKeyStartsANewRequest(): void
    {
        $product = $this->seedProduct();
        $customer = self::uuid();
        $first = $this->placeOrder($customer, self::orderBody($product), 'old-key');
        $this->connection->executeStatement("UPDATE idempotency_keys SET created_at = now() - interval '25 hours', expires_at = now() - interval '1 hour'");

        $second = $this->placeOrder($customer, self::orderBody($product), 'old-key');

        self::assertSame(201, $second->getStatusCode(), (string) $second->getContent());
        self::assertFalse($second->headers->has('Idempotent-Replayed'));
        self::assertNotSame(self::json($first)['orderId'], self::json($second)['orderId']);
        self::assertSame(2, $this->countRows('orders'));
    }

    public function testKeyIsNotRequiredForOtherEndpoints(): void
    {
        $customer = self::uuid();
        $order = $this->pendingOrder($customer);
        $this->persist($order);

        $response = $this->request('POST', sprintf('/api/orders/%s/cancel', $order->id->value), $customer);

        self::assertSame(202, $response->getStatusCode(), (string) $response->getContent());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function startedElsewhere(string $customer, string $key, array $body): void
    {
        $store = self::getContainer()->get(IdempotencyStore::class);
        $decision = $store->begin(
            CustomerId::fromString($customer),
            IdempotencyKey::fromHeader($key),
            RequestFingerprint::of('POST', '/api/orders', json_encode($body, JSON_THROW_ON_ERROR)),
            'POST',
            '/api/orders',
            self::now(),
        );
        self::assertSame('Started', $decision->outcome->name);
    }
}
