<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;

/**
 * EC-84: `myOrders { product { name } }` on 20 orders reads the products once.
 */
#[Group('M7.1')]
final class GraphQlBatchLoadingTest extends GraphQlTestCase
{
    #[Group('T-F-32')]
    public function testProductsOfAPageAreLoadedWithOneQuery(): void
    {
        $customer = self::uuid();

        for ($i = 0; $i < 20; ++$i) {
            $order = $this->pendingOrder($customer, new \DateTimeImmutable(sprintf('2026-03-01 10:00:%02d', $i), new \DateTimeZone('UTC')));
            $this->persist($order);
            $this->seedProductView($order->productId->value, 'Game ' . $i);
        }

        $this->recorder()->reset();
        $response = $this->graphql('{ myOrders { items { orderId product { name available } } } }', $customer);
        $statements = $this->recorder()->statements();

        $page = self::data($response)['myOrders'];
        self::assertIsArray($page);
        self::assertIsArray($page['items']);
        self::assertCount(20, $page['items']);

        foreach ($page['items'] as $item) {
            self::assertIsArray($item);
            self::assertIsArray($item['product'], 'every order of the page got its product');
        }

        self::assertSame(1, $this->recorder()->countContaining('FROM product_availability_view'), implode("\n", $statements));
        self::assertSame(1, $this->recorder()->countContaining('FROM order_view'));
    }

    #[Group('T-F-32')]
    public function testProductsOfOneOrderAndRepeatedIdsAreStillOneQuery(): void
    {
        $customer = self::uuid();
        $order = $this->pendingOrder($customer);
        $this->persist($order);
        $this->seedProductView($order->productId->value, 'Solo');

        $this->recorder()->reset();
        $response = $this->graphql(
            sprintf('{ order(id: "%s") { product { name } } myOrders { items { product { name } } } }', $order->id->value),
            $customer,
        );

        self::assertSame('Solo', self::productName(self::data($response)['order']));
        self::assertSame(1, $this->recorder()->countContaining('FROM product_availability_view'), 'the cache serves the second use');
    }

    private static function productName(mixed $order): mixed
    {
        self::assertIsArray($order);
        self::assertIsArray($order['product']);

        return $order['product']['name'];
    }
}
