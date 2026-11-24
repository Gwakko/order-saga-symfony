<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;

/**
 * `POST /api/orders/{id}/cancel` and `/refund` (02_use_cases.md sections 1.2 and 1.3).
 */
#[Group('M5.1')]
final class OrderActionsTest extends FunctionalTestCase
{
    #[Group('T-F-10')]
    public function testCancellingACompletedOrderIsRefused(): void
    {
        $customer = self::uuid();
        $order = $this->completedOrder($customer);
        $this->persist($order);

        $response = $this->request('POST', sprintf('/api/orders/%s/cancel', $order->id->value), $customer);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('ORDER_NOT_CANCELLABLE', self::json($response)['code']);
        self::assertSame(0, $this->countRows('orders', 'cancellation_requested'));
    }

    public function testCancelRecordsTheFlagAndIsRepeatable(): void
    {
        $customer = self::uuid();
        $order = $this->pendingOrder($customer);
        $this->persist($order);
        $uri = sprintf('/api/orders/%s/cancel', $order->id->value);

        $first = $this->request('POST', $uri, $customer);
        $second = $this->request('POST', $uri, $customer);

        self::assertSame(202, $first->getStatusCode(), (string) $first->getContent());
        self::assertSame(['orderId' => $order->id->value, 'status' => 'pending', 'cancellationRequested' => true], self::json($first));
        self::assertSame(202, $second->getStatusCode());
        self::assertSame(self::json($first), self::json($second));
        self::assertSame(1, $this->countRows('outbox_messages', "event_type = 'OrderCancellationRequested'"), 'a repeat adds no event');
    }

    #[Group('T-F-24')]
    public function testCancellingSomeoneElsesOrderLooksLikeMissing(): void
    {
        $owner = self::uuid();
        $order = $this->pendingOrder($owner);
        $this->persist($order);

        $foreign = $this->request('POST', sprintf('/api/orders/%s/cancel', $order->id->value), self::uuid());
        $missing = $this->request('POST', sprintf('/api/orders/%s/cancel', self::uuid()), self::uuid());

        self::assertSame(404, $foreign->getStatusCode());
        self::assertSame('ORDER_NOT_FOUND', self::json($foreign)['code']);
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame(self::json($missing)['code'], self::json($foreign)['code']);
        self::assertSame(0, $this->countRows('orders', 'cancellation_requested'));
    }

    #[Group('T-F-12')]
    public function testRefundStartsForARecentlyCompletedOrder(): void
    {
        $customer = self::uuid();
        $order = $this->completedOrder($customer);
        $this->persist($order);
        $uri = sprintf('/api/orders/%s/refund', $order->id->value);

        $first = $this->request('POST', $uri, $customer);
        $repeat = $this->request('POST', $uri, $customer);

        self::assertSame(202, $first->getStatusCode(), (string) $first->getContent());
        self::assertSame(['orderId' => $order->id->value, 'status' => 'refunding'], self::json($first));
        self::assertSame(202, $repeat->getStatusCode());
        self::assertSame(self::json($first), self::json($repeat));
        self::assertSame(1, $this->countRows('outbox_messages', "event_type = 'OrderRefundStarted'"));
    }

    public function testRefundOfAnUncompletedOrderIsRefused(): void
    {
        $customer = self::uuid();
        $order = $this->pendingOrder($customer);
        $this->persist($order);

        $response = $this->request('POST', sprintf('/api/orders/%s/refund', $order->id->value), $customer);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('ORDER_NOT_REFUNDABLE', self::json($response)['code']);
    }

    public function testRefundAfterTheWindowIsRefused(): void
    {
        $customer = self::uuid();
        $order = $this->completedOrder($customer, self::now()->modify('-15 days'));
        $this->persist($order);

        $response = $this->request('POST', sprintf('/api/orders/%s/refund', $order->id->value), $customer);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('REFUND_WINDOW_CLOSED', self::json($response)['code']);
    }

    #[Group('T-F-24')]
    public function testRefundOfSomeoneElsesOrderLooksLikeMissing(): void
    {
        $order = $this->completedOrder(self::uuid());
        $this->persist($order);

        $response = $this->request('POST', sprintf('/api/orders/%s/refund', $order->id->value), self::uuid());

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('ORDER_NOT_FOUND', self::json($response)['code']);
    }

    public function testMalformedOrderIdIsRejected(): void
    {
        $response = $this->request('POST', '/api/orders/not-a-uuid/cancel', self::uuid());

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('INVALID_IDENTIFIER', self::json($response)['code']);
    }
}
