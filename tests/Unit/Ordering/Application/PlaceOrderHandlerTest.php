<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\Command\PlaceOrder\PlaceOrderCommand;
use App\Ordering\Application\Command\PlaceOrder\PlaceOrderHandler;
use App\Ordering\Application\Exception\ProductNotFound;
use App\Ordering\Application\Exception\ProductUnavailable;
use App\Ordering\Application\Port\ProductSnapshot;
use App\Ordering\Domain\Event\OrderPlaced;
use App\Ordering\Domain\Exception\PriceChanged;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\DomainErrorCode;
use App\Shared\Domain\Exception\CurrencyMismatch;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Money;
use App\Tests\Support\Fake\InMemoryOrderRepository;
use App\Tests\Support\Fake\InMemoryProductCatalog;
use App\Tests\Support\Fake\NoopTransactionManager;
use App\Tests\Unit\Ordering\Domain\OrderFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * T-U-44: PlaceOrder (EC-66, EC-67, EC-69).
 */
#[Group('T-U-44')]
final class PlaceOrderHandlerTest extends TestCase
{
    private const OTHER_PRODUCT_ID = '0190a5c4-0000-7000-8000-0000000000f3';

    private InMemoryOrderRepository $orders;
    private InMemoryProductCatalog $catalog;
    private PlaceOrderHandler $handler;

    protected function setUp(): void
    {
        $this->orders = new InMemoryOrderRepository();
        $this->catalog = new InMemoryProductCatalog();
        $this->catalog->put(new ProductSnapshot(ProductId::fromString(OrderFactory::PRODUCT_ID), OrderFactory::price(), true));
        $this->handler = new PlaceOrderHandler($this->orders, $this->catalog, new NoopTransactionManager(), new MockClock('2026-05-01T10:00:00Z'));
    }

    private function command(int $amount = 1999, Currency $currency = Currency::EUR, string $productId = OrderFactory::PRODUCT_ID): PlaceOrderCommand
    {
        return new PlaceOrderCommand(OrderFactory::ORDER_ID, OrderFactory::CUSTOMER_ID, $productId, $amount, $currency);
    }

    public function testPlacesPendingOrderAndRecordsOrderPlaced(): void
    {
        $output = ($this->handler)($this->command());

        self::assertSame(OrderFactory::ORDER_ID, $output->orderId);
        self::assertSame(OrderStatus::Pending, $output->status);
        self::assertSame(1999, $output->price->amount);
        self::assertSame('EUR', $output->price->currency);
        self::assertEquals(OrderFactory::t0(), $output->createdAt);

        self::assertSame(OrderStatus::Pending, $this->orders->get(OrderFactory::pending()->id)->status);
        self::assertCount(1, $this->orders->events);
        self::assertInstanceOf(OrderPlaced::class, $this->orders->events[0]);
    }

    /**
     * EC-69: unknown product.
     */
    public function testUnknownProductIsNotFound(): void
    {
        try {
            ($this->handler)($this->command(productId: self::OTHER_PRODUCT_ID));
            self::fail('Expected ProductNotFound');
        } catch (ProductNotFound $e) {
            self::assertSame(DomainErrorCode::ProductNotFound, $e->code());
        }

        self::assertSame(0, $this->orders->saves);
    }

    /**
     * EC-69: inactive product.
     */
    public function testInactiveProductIsUnavailable(): void
    {
        $this->catalog->put(new ProductSnapshot(ProductId::fromString(OrderFactory::PRODUCT_ID), OrderFactory::price(), false));

        try {
            ($this->handler)($this->command());
            self::fail('Expected ProductUnavailable');
        } catch (ProductUnavailable $e) {
            self::assertSame(DomainErrorCode::ProductUnavailable, $e->code());
        }

        self::assertSame(0, $this->orders->saves);
    }

    /**
     * EC-66: the expected currency differs from the catalog currency.
     */
    public function testCurrencyMismatchCreatesNoOrder(): void
    {
        $this->expectException(CurrencyMismatch::class);

        try {
            ($this->handler)($this->command(currency: Currency::USD));
        } finally {
            self::assertSame(0, $this->orders->saves);
        }
    }

    /**
     * EC-67: the price changed; the error carries the current price.
     */
    public function testPriceChangedCarriesCurrentPrice(): void
    {
        try {
            ($this->handler)($this->command(amount: 1899));
            self::fail('Expected PriceChanged');
        } catch (PriceChanged $e) {
            self::assertSame(DomainErrorCode::PriceChanged, $e->code());
            self::assertTrue($e->current->equals(Money::positive(1999, Currency::EUR)));
        }

        self::assertSame(0, $this->orders->saves);
    }
}
