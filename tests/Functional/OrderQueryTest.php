<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;

/**
 * `GET /api/orders/{id}` and `GET /api/orders` (02_use_cases.md sections 4.1 and 4.2).
 */
#[Group('M5.1')]
final class OrderQueryTest extends FunctionalTestCase
{
    private const string ISO_UTC = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}\+00:00$/';

    public function testGetsOwnPendingOrder(): void
    {
        $customer = self::uuid();
        $order = $this->pendingOrder($customer);
        $this->persist($order);

        $response = $this->request('GET', '/api/orders/' . $order->id->value, $customer);
        $body = self::json($response);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame(
            ['orderId', 'status', 'price', 'productId', 'failureReason', 'cancellationRequested', 'keyCode', 'createdAt', 'updatedAt', 'completedAt'],
            array_keys($body),
        );
        self::assertSame($order->id->value, $body['orderId']);
        self::assertSame('pending', $body['status']);
        self::assertSame(['amount' => 1999, 'currency' => 'EUR'], $body['price']);
        self::assertNull($body['keyCode']);
        self::assertNull($body['failureReason']);
        self::assertNull($body['completedAt']);
        self::assertFalse($body['cancellationRequested']);
    }

    /**
     * INV-17: the key code leaves the system only here, and only while the order is `completed`.
     */
    public function testKeyCodeAppearsOnlyForACompletedOrder(): void
    {
        $customer = self::uuid();
        $order = $this->completedOrder($customer);
        $this->persist($order);
        $this->connection->executeStatement(
            "INSERT INTO product_keys (id, product_id, code, status, version) VALUES (:id, :product, 'ABCD-EFGH-1234', 'sold', 1)",
            ['id' => $order->deliveredKeyId?->value, 'product' => $order->productId->value],
        );

        $completed = self::json($this->request('GET', '/api/orders/' . $order->id->value, $customer));
        self::assertSame('completed', $completed['status']);
        self::assertSame('ABCD-EFGH-1234', $completed['keyCode']);
        self::assertMatchesRegularExpression(self::ISO_UTC, self::str($completed['completedAt']));

        $this->request('POST', sprintf('/api/orders/%s/refund', $order->id->value), $customer);
        $this->projectOutbox();
        $refunding = self::json($this->request('GET', '/api/orders/' . $order->id->value, $customer));

        self::assertSame('refunding', $refunding['status']);
        self::assertNull($refunding['keyCode']);
    }

    #[Group('T-F-24')]
    public function testSomeoneElsesOrderLooksLikeMissing(): void
    {
        $order = $this->pendingOrder(self::uuid());
        $this->persist($order);

        $foreign = $this->request('GET', '/api/orders/' . $order->id->value, self::uuid());
        $missing = $this->request('GET', '/api/orders/' . self::uuid(), self::uuid());

        self::assertSame(404, $foreign->getStatusCode());
        self::assertSame('ORDER_NOT_FOUND', self::json($foreign)['code']);
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame(self::json($missing)['code'], self::json($foreign)['code']);
        self::assertSame(self::json($missing)['title'], self::json($foreign)['title']);
    }

    #[Group('T-F-27')]
    public function testTimestampsAreIso8601UtcWithMilliseconds(): void
    {
        $customer = self::uuid();
        // A non-UTC instant with microseconds: the API still answers in UTC with exactly three decimals.
        $order = $this->pendingOrder($customer, new \DateTimeImmutable('2026-03-01 12:34:56.789654', new \DateTimeZone('Europe/Kyiv')));
        $this->persist($order);

        $single = self::json($this->request('GET', '/api/orders/' . $order->id->value, $customer));
        $list = self::json($this->request('GET', '/api/orders', $customer));

        self::assertSame('2026-03-01T10:34:56.789+00:00', $single['createdAt']);
        self::assertMatchesRegularExpression(self::ISO_UTC, self::str($single['updatedAt']));
        self::assertIsArray($list['items']);
        self::assertIsArray($list['items'][0]);
        self::assertSame('2026-03-01T10:34:56.789+00:00', $list['items'][0]['createdAt']);
        self::assertMatchesRegularExpression(self::ISO_UTC, self::str($list['items'][0]['updatedAt']));
    }

    #[Group('T-F-26')]
    public function testPagesNewestFirstWithoutGapsOrRepeats(): void
    {
        $customer = self::uuid();
        $expected = [];

        for ($i = 0; $i < 5; ++$i) {
            $order = $this->pendingOrder($customer, new \DateTimeImmutable(sprintf('2026-03-01 10:00:0%d', $i), new \DateTimeZone('UTC')));
            $this->persist($order);
            array_unshift($expected, $order->id->value);
        }

        // Someone else's orders never show up.
        $this->persist($this->pendingOrder(self::uuid()));

        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $uri = '/api/orders?limit=2' . ($cursor === null ? '' : '&cursor=' . rawurlencode($cursor));
            $response = $this->request('GET', $uri, $customer);
            $page = self::json($response);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            self::assertSame(['items', 'nextCursor'], array_keys($page));
            self::assertIsArray($page['items']);

            foreach ($page['items'] as $item) {
                self::assertIsArray($item);
                self::assertSame(['orderId', 'status', 'price', 'productId', 'createdAt', 'updatedAt'], array_keys($item));
                $seen[] = $item['orderId'];
            }

            $cursor = is_string($page['nextCursor']) ? $page['nextCursor'] : null;
            ++$pages;
        } while ($cursor !== null && $pages < 10);

        self::assertSame($expected, $seen);
        self::assertSame(3, $pages);
    }

    #[Group('T-F-26')]
    public function testDefaultsAndStatusFilter(): void
    {
        $customer = self::uuid();
        $this->persist($this->pendingOrder($customer));
        $this->persist($this->completedOrder($customer));

        $all = self::json($this->request('GET', '/api/orders', $customer));
        $completed = self::json($this->request('GET', '/api/orders?status=completed', $customer));
        $empty = self::json($this->request('GET', '/api/orders?status=refunded', $customer));

        self::assertIsArray($all['items']);
        self::assertCount(2, $all['items']);
        self::assertNull($all['nextCursor']);
        self::assertIsArray($completed['items']);
        self::assertCount(1, $completed['items']);
        self::assertSame([], $empty['items']);
        self::assertNull($empty['nextCursor']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badQueries(): iterable
    {
        yield 'EC-72 broken cursor' => ['cursor=%25%25%25', 'INVALID_CURSOR'];
        yield 'cursor of wrong content' => ['cursor=bm90LWEtY3Vyc29y', 'INVALID_CURSOR'];
        yield 'EC-73 limit 0' => ['limit=0', 'VALIDATION_FAILED'];
        yield 'EC-73 limit 101' => ['limit=101', 'VALIDATION_FAILED'];
        yield 'EC-73 limit abc' => ['limit=abc', 'VALIDATION_FAILED'];
        yield 'limit negative' => ['limit=-1', 'VALIDATION_FAILED'];
        yield 'limit fractional' => ['limit=1.5', 'VALIDATION_FAILED'];
        yield 'unknown status' => ['status=bogus', 'VALIDATION_FAILED'];
    }

    #[Group('T-F-26')]
    #[DataProvider('badQueries')]
    // Symfony builds the DTO with the raw value after it has recorded the type error: PHP reports the lossy coercion of "1.5".
    #[IgnoreDeprecations('Implicit conversion from float')]
    public function testInvalidQueryIsRejected(string $query, string $code): void
    {
        $response = $this->request('GET', '/api/orders?' . $query, self::uuid());

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame($code, self::json($response)['code']);
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
    }

    #[Group('T-F-26')]
    public function testLimitBoundsAreAccepted(): void
    {
        $customer = self::uuid();

        foreach ([1, 100] as $limit) {
            $response = $this->request('GET', '/api/orders?limit=' . $limit, $customer);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        }
    }

    public function testOverlongCursorIsAValidationError(): void
    {
        $response = $this->request('GET', '/api/orders?cursor=' . str_repeat('A', 201), self::uuid());
        $problem = self::json($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('VALIDATION_FAILED', $problem['code']);
        self::assertIsArray($problem['errors']);
        self::assertIsArray($problem['errors'][0]);
        self::assertSame('cursor', $problem['errors'][0]['propertyPath']);
    }

    public function testUnknownQueryParametersAreIgnored(): void
    {
        $response = $this->request('GET', '/api/orders?utm_source=x', self::uuid());

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }
}
