<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Shared\Domain\Identifier\PaymentId;
use App\Shared\Domain\Identifier\ReservationId;
use App\Shared\Domain\RefundWindow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /graphql`, read side only (02_use_cases.md section 4, U2).
 */
#[Group('M7.1')]
final class GraphQlQueryTest extends GraphQlTestCase
{
    private const string ORDER_FIELDS = 'orderId status price { amount currency } productId failureReason cancellationRequested keyCode createdAt updatedAt completedAt';

    public function testReadsOwnPendingOrder(): void
    {
        $customer = self::uuid();
        $order = $this->pendingOrder($customer);
        $this->persist($order);

        $response = $this->graphql(sprintf('{ order(id: "%s") { %s } }', $order->id->value, self::ORDER_FIELDS), $customer);
        $rest = self::json($this->request('GET', '/api/orders/' . $order->id->value, $customer));
        $data = self::data($response);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertArrayNotHasKey('errors', self::json($response));
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertIsArray($data['order']);
        self::assertSame('PENDING', $data['order']['status']);
        self::assertSame(['amount' => 1999, 'currency' => 'EUR'], $data['order']['price'], 'money is an integer in minor units');
        // The same values as REST, except the enum spelling.
        self::assertSame([...$rest, 'status' => 'PENDING'], $data['order']);
    }

    #[Group('T-F-24')]
    public function testSomeoneElsesOrderLooksLikeMissingInGraphQl(): void
    {
        $order = $this->pendingOrder(self::uuid());
        $this->persist($order);

        $foreign = $this->graphql(sprintf('{ order(id: "%s") { orderId } }', $order->id->value), self::uuid());
        $missing = $this->graphql(sprintf('{ order(id: "%s") { orderId } }', self::uuid()), self::uuid());
        $restForeign = $this->request('GET', '/api/orders/' . $order->id->value, self::uuid());

        self::assertSame(['order' => null], self::data($foreign));
        self::assertSame('ORDER_NOT_FOUND', self::errorCode($foreign));
        self::assertSame(self::json($restForeign)['code'], self::errorCode($foreign), 'the REST code');
        self::assertSame(['order'], self::firstError($foreign)['path']);
        // Foreign and missing are indistinguishable (EC-70): same code, same status, same text up to the id.
        self::assertSame(self::errorCode($missing), self::errorCode($foreign));
        self::assertSame(404, self::extensions($foreign)['status']);
        self::assertSame(self::extensions($missing), self::extensions($foreign));
        self::assertSame(200, $foreign->getStatusCode());
    }

    #[Group('T-F-31')]
    public function testKeyCodeIsReturnedOnlyForACompletedOrder(): void
    {
        $customer = self::uuid();
        $completed = $this->completedOrder($customer);
        $this->persist($completed);
        $this->connection->executeStatement(
            "INSERT INTO product_keys (id, product_id, code, status, version) VALUES (:id, :product, 'ABCD-EFGH-1234', 'sold', 1)",
            ['id' => $completed->deliveredKeyId?->value, 'product' => $completed->productId->value],
        );

        $paid = $this->pendingOrder($customer)->markKeyReserved(ReservationId::fromString(self::uuid()), self::now())
            ->markPaid(PaymentId::fromString(self::uuid()), self::now());
        $this->persist($paid);

        $refunded = $this->completedOrder($customer);
        $refunded = $refunded->startRefund(self::now(), new RefundWindow())->finishRefund(self::now());
        $this->persist($refunded);

        $keyOf = fn(string $id): mixed => self::data($this->graphql(sprintf('{ order(id: "%s") { status keyCode } }', $id), $customer))['order'];

        self::assertSame(['status' => 'COMPLETED', 'keyCode' => 'ABCD-EFGH-1234'], $keyOf($completed->id->value));
        self::assertSame(['status' => 'PAID', 'keyCode' => null], $keyOf($paid->id->value));
        self::assertSame(['status' => 'REFUNDED', 'keyCode' => null], $keyOf($refunded->id->value));
    }

    #[Group('T-F-31')]
    public function testListRowsHaveNoKeyCodeField(): void
    {
        $response = $this->graphql('{ myOrders { items { keyCode } } }', self::uuid());

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('GRAPHQL_VALIDATION_FAILED', self::errorCode($response));
    }

    public function testPagesNewestFirstThroughCursorsAndFiltersByStatus(): void
    {
        $customer = self::uuid();
        $expected = [];

        for ($i = 0; $i < 5; ++$i) {
            $order = $this->pendingOrder($customer, new \DateTimeImmutable(sprintf('2026-03-01 10:00:0%d', $i), new \DateTimeZone('UTC')));
            $this->persist($order);
            array_unshift($expected, $order->id->value);
        }

        $this->persist($this->completedOrder($customer));
        $this->persist($this->pendingOrder(self::uuid()));

        $query = 'query($cursor: String, $status: OrderStatus) { myOrders(status: $status, limit: 2, cursor: $cursor) { items { orderId status price { amount } } nextCursor } }';
        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $page = self::data($this->graphql($query, $customer, ['cursor' => $cursor, 'status' => 'PENDING']))['myOrders'];
            self::assertIsArray($page);
            self::assertIsArray($page['items']);

            foreach ($page['items'] as $item) {
                self::assertIsArray($item);
                self::assertSame('PENDING', $item['status']);
                $seen[] = $item['orderId'];
            }

            $cursor = is_string($page['nextCursor']) ? $page['nextCursor'] : null;
            ++$pages;
        } while ($cursor !== null && $pages < 10);

        self::assertSame($expected, $seen);
        self::assertSame(3, $pages);

        $default = self::data($this->graphql('{ myOrders { items { orderId } nextCursor } }', $customer))['myOrders'];
        self::assertIsArray($default);
        self::assertIsArray($default['items']);
        self::assertCount(6, $default['items'], 'default page of 20 holds everything of this caller');
        self::assertNull($default['nextCursor']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badListArguments(): iterable
    {
        yield 'EC-72 broken cursor' => ['cursor: "%%%"', 'INVALID_CURSOR'];
        yield 'cursor of wrong content' => ['cursor: "bm90LWEtY3Vyc29y"', 'INVALID_CURSOR'];
        yield 'EC-73 limit 0' => ['limit: 0', 'VALIDATION_FAILED'];
        yield 'EC-73 limit 101' => ['limit: 101', 'VALIDATION_FAILED'];
        yield 'limit negative' => ['limit: -1', 'VALIDATION_FAILED'];
    }

    #[DataProvider('badListArguments')]
    public function testInvalidListArgumentsUseTheRestErrorCodes(string $arguments, string $code): void
    {
        $response = $this->graphql(sprintf('{ myOrders(%s) { nextCursor } }', $arguments), self::uuid());

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertNull(self::json($response)['data']);
        self::assertSame($code, self::errorCode($response));
        self::assertSame(422, self::extensions($response)['status']);
    }

    public function testUnknownStatusIsAValidationError(): void
    {
        $response = $this->graphql('{ myOrders(status: BOGUS) { nextCursor } }', self::uuid());

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('GRAPHQL_VALIDATION_FAILED', self::errorCode($response));
    }

    public function testBadOrderIdIsAnInvalidIdentifier(): void
    {
        $response = $this->graphql('{ order(id: "not-a-uuid") { orderId } }', self::uuid());
        $rest = $this->request('GET', '/api/orders/not-a-uuid', self::uuid());

        self::assertSame(self::json($rest)['code'], self::errorCode($response));
        self::assertSame(['order' => null], self::data($response));
    }

    public function testReadsProductAvailability(): void
    {
        $id = self::uuid();
        $this->seedProductView($id, 'Hades', 2499, 7);

        $response = $this->graphql(sprintf('{ product(id: "%s") { productId name price { amount currency } available } }', $id), self::uuid());

        self::assertSame(
            ['product' => ['productId' => $id, 'name' => 'Hades', 'price' => ['amount' => 2499, 'currency' => 'EUR'], 'available' => 7]],
            self::data($response),
        );
    }

    public function testMissingProductIsProductNotFound(): void
    {
        $response = $this->graphql(sprintf('{ product(id: "%s") { name } }', self::uuid()), self::uuid());

        self::assertSame(['product' => null], self::data($response));
        self::assertSame('PRODUCT_NOT_FOUND', self::errorCode($response));
        self::assertSame(404, self::extensions($response)['status']);
    }

    public function testOrderProductIsNullUntilTheReadModelHasIt(): void
    {
        $customer = self::uuid();
        $order = $this->pendingOrder($customer);
        $this->persist($order);

        $response = $this->graphql(sprintf('{ order(id: "%s") { product { name } } }', $order->id->value), $customer);

        self::assertSame(['order' => ['product' => null]], self::data($response));
        self::assertArrayNotHasKey('errors', self::json($response));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function badCustomers(): iterable
    {
        yield 'absent' => [null];
        yield 'not a UUID' => ['abc'];
        yield 'empty' => [''];
    }

    #[DataProvider('badCustomers')]
    public function testCustomerIsRequired(?string $header): void
    {
        $this->recorder()->reset();
        $response = $this->graphql('{ myOrders { nextCursor } }', $header);

        self::assertSame(401, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('CUSTOMER_REQUIRED', self::errorCode($response));
        self::assertSame(0, $this->recorder()->countContaining('order_view'), 'nothing ran');
    }

    public function testThereAreNoMutations(): void
    {
        $response = $this->graphql('mutation { placeOrder }', self::uuid());

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('GRAPHQL_VALIDATION_FAILED', self::errorCode($response));
    }

    public function testMalformedBodiesAreBadRequests(): void
    {
        foreach (['not json', '[]', '{"query": 5}', '{"query": "{ __typename }", "variables": "x"}'] as $body) {
            $response = $this->request('POST', '/graphql', self::uuid(), $body);

            self::assertSame(400, $response->getStatusCode(), $body);
            self::assertSame('BAD_REQUEST', self::errorCode($response), $body);
        }
    }

    public function testSyntaxErrorIsAClientError(): void
    {
        $response = $this->graphql('{ order(', self::uuid());

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('GRAPHQL_VALIDATION_FAILED', self::errorCode($response));
    }

    public function testOnlyPostIsAccepted(): void
    {
        $response = $this->request('GET', '/graphql?query=%7B__typename%7D', self::uuid());

        self::assertSame(405, $response->getStatusCode());
    }

    public function testOversizedBodyIsRejected(): void
    {
        $body = json_encode(['query' => '{ __typename }' . str_repeat(' ', 20_000)], JSON_THROW_ON_ERROR);
        $response = $this->request('POST', '/graphql', self::uuid(), $body);

        self::assertSame(413, $response->getStatusCode());
        self::assertSame('PAYLOAD_TOO_LARGE', self::errorCode($response));
    }

    /**
     * @return array<string, mixed>
     */
    private static function extensions(Response $response): array
    {
        $extensions = self::firstError($response)['extensions'] ?? null;
        self::assertIsArray($extensions);

        /** @var array<string, mixed> $extensions */
        return $extensions;
    }
}
