<?php

declare(strict_types=1);

namespace App\Tests\Integration\Projection;

use App\Ordering\Application\Port\OrderReadModel;
use App\Ordering\Application\Query\GetOrder\OrderDetailsOutput;
use App\Ordering\Application\Query\ListCustomerOrders\OrderCursor;
use App\Ordering\Application\Query\ListCustomerOrders\OrderSummaryOutput;
use App\Ordering\Domain\OrderStatus;
use App\Ordering\Infrastructure\Persistence\DbalOrderReadModel;
use App\Shared\Application\Output\MoneyOutput;
use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Infrastructure\Persistence\DbTimestamp;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Support\Fake\InMemoryOrderReadModel;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Group;

/**
 * The SQL read model and the in-memory one used by the application tests must answer the same: ownership filter,
 * `created_at DESC, order_id DESC`, strict keyset cursor, status filter, key code only for Completed (INV-17).
 */
#[Group('M4.6')]
final class OrderReadModelContractTest extends IntegrationTestCase
{
    private const string ALICE = '0190a5c4-1000-7000-8000-00000000a11c';
    private const string BOB = '0190a5c4-1000-7000-8000-0000000000b0';

    private InMemoryOrderReadModel $memory;
    private DbalOrderReadModel $sql;

    protected function setUp(): void
    {
        parent::setUp();

        $this->memory = new InMemoryOrderReadModel();
        $this->sql = new DbalOrderReadModel($this->connection);
        $this->seed();
    }

    public function testListIsIdenticalForEveryFilterLimitAndCursorPosition(): void
    {
        $customers = [CustomerId::fromString(self::ALICE), CustomerId::fromString(self::BOB), CustomerId::fromString(self::uuid())];
        $statuses = [null, ...OrderStatus::cases()];
        $compared = 0;

        foreach ($customers as $customer) {
            foreach ($statuses as $status) {
                foreach ([1, 2, 3, 5, 100] as $limit) {
                    $after = null;

                    for ($page = 0; $page < 20; ++$page) {
                        $expected = $this->memory->list($customer, $status, $limit, $after);
                        $actual = $this->sql->list($customer, $status, $limit, $after);

                        self::assertEquals($expected, $actual, sprintf('customer %s status %s limit %d page %d', $customer->value, $status->value ?? 'any', $limit, $page));
                        ++$compared;

                        $last = end($expected);

                        if ($last === false || count($expected) < $limit) {
                            break;
                        }

                        $after = new OrderCursor($last->createdAt, OrderId::fromString($last->orderId));
                    }
                }
            }
        }

        self::assertGreaterThan(150, $compared);
    }

    public function testOrderIsNewestFirstWithOrderIdAsTieBreaker(): void
    {
        $ids = array_map(
            static fn(OrderSummaryOutput $o): string => $o->orderId,
            $this->sql->list(CustomerId::fromString(self::ALICE), null, 100, null),
        );

        self::assertCount(8, $ids);
        $ties = array_values(array_filter($ids, static fn(string $id): bool => str_starts_with($id, '0190a5c4-2000-7000-8000-00000000000')));
        $sorted = $ties;
        rsort($sorted);
        self::assertSame($sorted, $ties, 'equal created_at: order_id DESC');
        self::assertCount(3, $ties);
    }

    public function testCursorIsStrict(): void
    {
        $page = $this->sql->list(CustomerId::fromString(self::ALICE), null, 3, null);
        $last = $page[2];
        $next = $this->sql->list(CustomerId::fromString(self::ALICE), null, 100, new OrderCursor($last->createdAt, OrderId::fromString($last->orderId)));

        self::assertNotContains($last->orderId, array_map(static fn(OrderSummaryOutput $o): string => $o->orderId, $next));
        self::assertCount(5, $next);
    }

    public function testFindIsIdenticalIncludingOwnershipAndKeyCode(): void
    {
        $alice = CustomerId::fromString(self::ALICE);
        $bob = CustomerId::fromString(self::BOB);

        foreach ($this->all as [$customerId, $details]) {
            $orderId = OrderId::fromString($details->orderId);
            self::assertEquals($this->memory->find($orderId, CustomerId::fromString($customerId)), $this->sql->find($orderId, CustomerId::fromString($customerId)), $details->orderId);
            self::assertNull($this->sql->find($orderId, CustomerId::fromString($customerId === self::ALICE ? self::BOB : self::ALICE)), 'a foreign order looks like a missing one');
        }

        self::assertNull($this->sql->find(OrderId::fromString(self::uuid()), $alice));
        self::assertNull($this->memory->find(OrderId::fromString(self::uuid()), $bob));
    }

    public function testKeyCodeIsShownOnlyWhileCompleted(): void
    {
        $completed = $this->sql->find(OrderId::fromString('0190a5c4-2000-7000-8000-0000000000c1'), CustomerId::fromString(self::ALICE));
        $refunded = $this->sql->find(OrderId::fromString('0190a5c4-2000-7000-8000-0000000000c2'), CustomerId::fromString(self::ALICE));

        self::assertNotNull($completed);
        self::assertSame('KEY-SHOWN-0001', $completed->keyCode);
        self::assertNotNull($refunded);
        self::assertNull($refunded->keyCode, 'a refunded order no longer reveals the key (INV-17)');
        self::assertNotNull($refunded->completedAt);
    }

    /** @var list<array{string, OrderDetailsOutput}> */
    private array $all = [];

    private function seed(): void
    {
        $product = $this->seedProduct();
        $this->connection->executeStatement(
            "INSERT INTO product_keys (id, product_id, code, status, version) VALUES (:id, :p, 'KEY-SHOWN-0001', 'sold', 1)",
            ['id' => '0190a5c4-3000-7000-8000-000000000001', 'p' => $product->id->value],
        );
        $keyId = '0190a5c4-3000-7000-8000-000000000001';
        $t = new \DateTimeImmutable('2026-05-01T10:00:00.000000Z');

        // Alice: three orders share one created_at (tie), the rest differ by microseconds and seconds.
        $this->put(self::ALICE, '0190a5c4-2000-7000-8000-000000000001', OrderStatus::Pending, $t->modify('+10 seconds'));
        $this->put(self::ALICE, '0190a5c4-2000-7000-8000-000000000002', OrderStatus::Pending, $t->modify('+10 seconds'));
        $this->put(self::ALICE, '0190a5c4-2000-7000-8000-000000000003', OrderStatus::Paid, $t->modify('+10 seconds'));
        $this->put(self::ALICE, '0190a5c4-2000-7000-8000-0000000000a4', OrderStatus::Failed, new \DateTimeImmutable('2026-05-01T10:00:10.000001Z'), failure: 'PaymentDeclined');
        $this->put(self::ALICE, '0190a5c4-2000-7000-8000-0000000000a5', OrderStatus::Cancelled, $t->modify('+5 seconds'), failure: 'CancelledByCustomer', cancel: true);
        $this->put(self::ALICE, '0190a5c4-2000-7000-8000-0000000000c1', OrderStatus::Completed, $t->modify('+1 second'), key: $keyId, completed: $t->modify('+2 seconds'));
        $this->put(self::ALICE, '0190a5c4-2000-7000-8000-0000000000c2', OrderStatus::Refunded, $t, key: $keyId, completed: $t->modify('+1 second'));
        $this->put(self::ALICE, '0190a5c4-2000-7000-8000-0000000000a8', OrderStatus::Compensating, new \DateTimeImmutable('2026-05-01T09:59:59.999999Z'));

        $this->put(self::BOB, '0190a5c4-2000-7000-8000-0000000000b1', OrderStatus::Pending, $t->modify('+10 seconds'));
        $this->put(self::BOB, '0190a5c4-2000-7000-8000-0000000000b2', OrderStatus::ManualReview, $t->modify('+9 seconds'), failure: 'CompensationFailed');
    }

    private function put(string $customerId, string $orderId, OrderStatus $status, \DateTimeImmutable $createdAt, ?string $failure = null, bool $cancel = false, ?string $key = null, ?\DateTimeImmutable $completed = null): void
    {
        $productId = '0190a5c4-4000-7000-8000-000000000001';
        $updated = $createdAt->modify('+3 seconds');

        $this->connection->executeStatement(
            'INSERT INTO order_view (order_id, customer_id, product_id, status, price_amount, price_currency, failure_reason,
                                     cancellation_requested, delivered_key_id, created_at, updated_at, completed_at, version)
             VALUES (:order_id, :customer_id, :product_id, :status, 1999, \'EUR\', :failure, :cancel, :key, :created, :updated, :completed, 3)',
            [
                'order_id' => $orderId,
                'customer_id' => $customerId,
                'product_id' => $productId,
                'status' => $status->value,
                'failure' => $failure,
                'cancel' => $cancel,
                'key' => $key,
                'created' => DbTimestamp::format($createdAt),
                'updated' => DbTimestamp::format($updated),
                'completed' => DbTimestamp::formatNullable($completed),
            ],
            ['cancel' => ParameterType::BOOLEAN],
        );

        $details = new OrderDetailsOutput(
            $orderId,
            $status,
            new MoneyOutput(1999, 'EUR'),
            $productId,
            $failure,
            $cancel,
            $status === OrderStatus::Completed ? 'KEY-SHOWN-0001' : null,
            $createdAt,
            $updated,
            $completed,
        );
        $this->memory->seed(CustomerId::fromString($customerId), $details);
        $this->all[] = [$customerId, $details];
    }
}
