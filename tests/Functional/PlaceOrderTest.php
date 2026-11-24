<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;

#[Group('M5.1')]
final class PlaceOrderTest extends FunctionalTestCase
{
    public function testPlacesOrder(): void
    {
        $product = $this->seedProduct(1999);
        $customer = self::uuid();

        $response = $this->placeOrder($customer, self::orderBody($product));
        $body = self::json($response);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame('/api/orders/' . self::str($body['orderId']), $response->headers->get('Location'));
        self::assertSame(1, $this->countRows('orders', sprintf("customer_id = '%s' AND status = 'pending'", $customer)));
        self::assertSame(1, $this->countRows('outbox_messages', "event_type = 'OrderPlaced'"));
        self::assertFalse($response->headers->has('Idempotent-Replayed'));
    }

    /**
     * EC-54: the POST answer is a complete snapshot; the read model catches up asynchronously, so an immediate GET may
     * be 404 (documented behaviour) and succeeds once the projection has run.
     */
    #[Group('T-F-11')]
    public function testResponseContainsFullSnapshotEvenBeforeProjection(): void
    {
        $product = $this->seedProduct(1999);
        $customer = self::uuid();

        $created = self::json($this->placeOrder($customer, self::orderBody($product)));

        self::assertSame(['orderId', 'status', 'price', 'createdAt'], array_keys($created));
        self::assertIsString($created['orderId']);
        self::assertSame('pending', $created['status']);
        self::assertSame(['amount' => 1999, 'currency' => 'EUR'], $created['price']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}\+00:00$/', self::str($created['createdAt']));

        $early = $this->request('GET', '/api/orders/' . self::str($created['orderId']), $customer);
        self::assertSame(404, $early->getStatusCode());
        self::assertSame('ORDER_NOT_FOUND', self::json($early)['code']);

        $this->projectOutbox();

        $later = $this->request('GET', '/api/orders/' . self::str($created['orderId']), $customer);
        $view = self::json($later);
        self::assertSame(200, $later->getStatusCode(), (string) $later->getContent());
        self::assertSame($created['orderId'], $view['orderId']);
        self::assertSame('pending', $view['status']);
        self::assertSame($created['price'], $view['price']);
        self::assertSame($product, $view['productId']);
    }

    #[Group('T-F-21')]
    public function testCurrencyMismatchIsRejected(): void
    {
        $product = $this->seedProduct(1999, 'EUR');

        $response = $this->placeOrder(self::uuid(), self::orderBody($product, 1999, 'USD'));

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('CURRENCY_MISMATCH', self::json($response)['code']);
        self::assertSame(0, $this->countRows('orders'));
        self::assertSame(0, $this->countRows('outbox_messages'));
    }

    #[Group('T-F-22')]
    public function testPriceChangeCarriesCurrentPrice(): void
    {
        $product = $this->seedProduct(1999);

        $response = $this->placeOrder(self::uuid(), self::orderBody($product, 1899));
        $problem = self::json($response);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('PRICE_CHANGED', $problem['code']);
        self::assertSame(['amount' => 1999, 'currency' => 'EUR'], $problem['currentPrice']);
        self::assertSame(0, $this->countRows('orders'));
    }

    #[Group('T-F-23')]
    public function testUnknownProductIsNotFound(): void
    {
        $response = $this->placeOrder(self::uuid(), self::orderBody(self::uuid()));

        self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('PRODUCT_NOT_FOUND', self::json($response)['code']);
        self::assertSame(0, $this->countRows('orders'));
    }

    #[Group('T-F-23')]
    public function testInactiveProductIsUnavailable(): void
    {
        $product = $this->seedProduct(1999, 'EUR', false);

        $response = $this->placeOrder(self::uuid(), self::orderBody($product));

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('PRODUCT_UNAVAILABLE', self::json($response)['code']);
        self::assertSame(0, $this->countRows('orders'));
    }

    public function testUpperCaseProductIdIsAccepted(): void
    {
        $product = $this->seedProduct(1999);

        $response = $this->placeOrder(self::uuid(), self::orderBody(strtoupper($product)));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    }

    public function testUnknownRouteAnswersWithProblemDetails(): void
    {
        $response = $this->request('GET', '/api/nothing', self::uuid());

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame('NOT_FOUND', self::json($response)['code']);
    }

    public function testWrongMethodAnswersWithProblemDetails(): void
    {
        $response = $this->request('DELETE', '/api/orders', self::uuid());

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('METHOD_NOT_ALLOWED', self::json($response)['code']);
    }
}
