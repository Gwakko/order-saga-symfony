<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Domain;

use App\Ordering\Domain\Event\OrderPlaced;
use App\Ordering\Domain\Exception\PriceChanged;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\DomainErrorCode;
use App\Shared\Domain\Exception\CurrencyMismatch;
use App\Shared\Domain\Exception\NonPositiveMoney;
use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Money;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class OrderPlaceTest extends TestCase
{
    public function testPlaceCreatesPendingOrderWithOrderPlacedEvent(): void
    {
        $order = OrderFactory::pending();

        self::assertSame(OrderStatus::Pending, $order->status);
        self::assertSame(1, $order->version);
        self::assertTrue($order->price->equals(OrderFactory::price()));
        self::assertFalse($order->cancellationRequested);
        self::assertNull($order->failureReason);
        self::assertCount(1, $order->pendingEvents);
        self::assertInstanceOf(OrderPlaced::class, $order->pendingEvents[0]);
        self::assertSame(1, $order->pendingEvents[0]->aggregateVersion());
        self::assertSame(OrderFactory::ORDER_ID, $order->pendingEvents[0]->aggregateId());
    }

    /**
     * EC-66: catalog EUR, client expects USD.
     */
    #[Group('T-U-02')]
    public function testCurrencyMismatch(): void
    {
        try {
            $this->place(Money::positive(1999, Currency::EUR), Money::positive(1999, Currency::USD));
            self::fail('Expected CurrencyMismatch');
        } catch (CurrencyMismatch $e) {
            self::assertSame(DomainErrorCode::CurrencyMismatch, $e->code());
        }
    }

    /**
     * EC-67: catalog 1999, client expects 1899; the error carries the current price.
     */
    #[Group('T-U-03')]
    public function testPriceChanged(): void
    {
        try {
            $this->place(Money::positive(1999, Currency::EUR), Money::positive(1899, Currency::EUR));
            self::fail('Expected PriceChanged');
        } catch (PriceChanged $e) {
            self::assertSame(DomainErrorCode::PriceChanged, $e->code());
            self::assertSame(1999, $e->current->amount);
            self::assertSame(Currency::EUR, $e->current->currency);
        }
    }

    public function testExpectedPriceHigherThanCatalogIsAlsoPriceChanged(): void
    {
        $this->expectException(PriceChanged::class);
        $this->place(Money::positive(1999, Currency::EUR), Money::positive(2000, Currency::EUR));
    }

    public function testZeroCatalogPriceRejected(): void
    {
        $this->expectException(NonPositiveMoney::class);
        $this->place(Money::of(0, Currency::EUR), Money::of(0, Currency::EUR));
    }

    private function place(Money $catalogPrice, Money $expectedPrice): Order
    {
        return Order::place(
            OrderId::fromString(OrderFactory::ORDER_ID),
            CustomerId::fromString(OrderFactory::CUSTOMER_ID),
            ProductId::fromString(OrderFactory::PRODUCT_ID),
            $catalogPrice,
            $expectedPrice,
            OrderFactory::t0(),
        );
    }
}
