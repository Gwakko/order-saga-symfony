<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use App\Inventory\Application\Command\ConsumeReservation\ConsumeReservationCommand;
use App\Inventory\Application\Command\ReserveKey\ReserveKeyCommand;
use App\Inventory\Domain\Product;
use App\Inventory\Domain\ProductKey;
use App\Ordering\Domain\Order;
use App\Payment\Domain\Payment;
use App\Payment\Infrastructure\Gateway\HttpPaymentGateway;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Domain\Identifier\PaymentId;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Identifier\ProductKeyId;
use App\Shared\Domain\Identifier\ReservationId;
use App\Shared\Domain\KeyCode;
use App\Shared\Domain\Money;
use App\Shared\Domain\RefundWindow;
use App\Shared\Infrastructure\Id\SymfonyUuidGenerator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Clock\MockClock;

/**
 * A delivered order in the real database: a sold key, a consumed reservation, a payment charged at the gateway
 * emulator and a `Completed` order (or one already `Refunding`). Times are close to the real now because the real
 * worker applies its own clock.
 */
final readonly class RefundFixture
{
    public function __construct(
        private Infra $infra,
        private Connection $connection,
        private HttpPaymentGateway $gateway,
    ) {}

    /**
     * @return array{Order, Payment} the stored order (not yet refunding unless asked) and its succeeded payment
     */
    public function create(bool $refunding): array
    {
        $uuid = new SymfonyUuidGenerator();
        $base = new \DateTimeImmutable('-5 minutes');
        $money = Money::positive(1999, Currency::EUR);
        $product = $this->product($uuid);
        $orderId = OrderId::fromString($uuid->next());

        $reserved = ($this->infra->reserveKey(new MockClock($base)))(new ReserveKeyCommand($orderId->value, $product->id->value));

        $payment = Payment::create(PaymentId::fromString($uuid->next()), $orderId, $money, $base);
        $this->infra->payments->insertOrGet($payment);
        $charge = $this->gateway->charge($payment->id->value, $money);
        $this->infra->payments->save($payment->markSucceeded($charge->chargeId, Currency::EUR, $base->modify('+2 seconds')));

        $consumed = ($this->infra->consumeReservation(new MockClock($base->modify('+3 seconds'))))(new ConsumeReservationCommand($orderId->value));

        $order = Order::place($orderId, CustomerId::fromString($uuid->next()), $product->id, $money, $money, $base)
            ->markKeyReserved(ReservationId::fromString($reserved->reservationId), $base->modify('+1 second'))
            ->markPaid($payment->id, $base->modify('+2 seconds'))
            ->complete(ProductKeyId::fromString($consumed->keyId), new KeyCode('ABCD-EFGH-1234'), $base->modify('+3 seconds'));

        if ($refunding) {
            $order = $order->startRefund($base->modify('+4 seconds'), new RefundWindow());
        }

        $this->infra->orders->save($order);

        return [$order, $this->infra->payments->findByOrder($orderId) ?? throw new \LogicException('payment not stored')];
    }

    private function product(SymfonyUuidGenerator $uuid): Product
    {
        $product = new Product(ProductId::fromString($uuid->next()), 'Game', Money::positive(1999, Currency::EUR), true);
        $this->connection->executeStatement(
            'INSERT INTO products (id, name, price_amount, price_currency, active) VALUES (:id, :name, :amount, :currency, :active)',
            ['id' => $product->id->value, 'name' => $product->name, 'amount' => $product->price->amount, 'currency' => $product->price->currency->value, 'active' => true],
            ['active' => ParameterType::BOOLEAN],
        );
        $this->infra->keys->addIfAbsent(ProductKey::add(ProductKeyId::fromString($uuid->next()), $product->id, new KeyCode('KEY-' . strtoupper(substr(md5($product->id->value), 0, 6)) . '-0001')));

        return $product;
    }
}
