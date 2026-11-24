<?php

declare(strict_types=1);

namespace App\Tests\Integration\Projection;

use App\Inventory\Application\Command\ReserveKey\ReserveKeyCommand;
use App\Inventory\Application\Query\GetProductAvailability\ProductAvailabilityOutput;
use App\Inventory\Infrastructure\Persistence\DbalProductReadModel;
use App\Inventory\Infrastructure\Projection\ProductAvailabilityProjection;
use App\Shared\Application\Output\MoneyOutput;
use App\Shared\Domain\Identifier\ProductId;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\Support\RecordingOutboxPublisher;
use App\Tests\Support\Fake\InMemoryProductReadModel;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;

#[Group('M4.6')]
final class ProductAvailabilityTest extends IntegrationTestCase
{
    private ProductAvailabilityProjection $projection;
    private DbalProductReadModel $readModel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projection = self::getContainer()->get(ProductAvailabilityProjection::class);
        $this->readModel = new DbalProductReadModel($this->connection);
    }

    public function testReadModelAnswersLikeTheInMemoryOne(): void
    {
        $product = $this->seedProduct(price: 2599);
        $this->connection->executeStatement(
            "INSERT INTO product_availability_view (product_id, name, price_amount, price_currency, available, updated_at)
             VALUES (:id, 'Game', 2599, 'EUR', 4, now())",
            ['id' => $product->id->value],
        );
        $expected = new ProductAvailabilityOutput($product->id->value, 'Game', new MoneyOutput(2599, 'EUR'), 4);
        $memory = new InMemoryProductReadModel();
        $memory->put($expected);

        self::assertEquals($memory->find($product->id), $this->readModel->find($product->id));
        self::assertNull($this->readModel->find(ProductId::fromString(self::uuid())));
    }

    #[Group('T-F-32')]
    public function testFindManyReturnsTheExistingRowsOfAllIdsInOneStatement(): void
    {
        $first = $this->seedProduct(price: 1000);
        $second = $this->seedProduct(price: 2000);
        $missing = ProductId::fromString(self::uuid());

        foreach ([$first, $second] as $product) {
            $this->connection->executeStatement(
                "INSERT INTO product_availability_view (product_id, name, price_amount, price_currency, available, updated_at)
                 VALUES (:id, 'Game', 1000, 'EUR', 2, now())",
                ['id' => $product->id->value],
            );
        }

        $found = $this->readModel->findMany([$first->id, $missing, $second->id]);
        $ids = array_map(static fn(ProductAvailabilityOutput $p): string => $p->productId, $found);
        sort($ids);
        $expected = [$first->id->value, $second->id->value];
        sort($expected);

        self::assertSame($expected, $ids);
        self::assertSame([], $this->readModel->findMany([]));
    }

    public function testAvailabilityFollowsReservationsExpiryAndRepeats(): void
    {
        $product = $this->seedProduct();
        $this->seedKeys($product->id, 3);
        $this->projection->refresh($product->id);
        self::assertSame(3, $this->readModel->find($product->id)?->available);

        $clock = new MockClock('2026-05-01T10:00:00Z');
        $this->infra->reserveKey($clock)(new ReserveKeyCommand(self::uuid(), $product->id->value));
        $this->deliverNewOutboxMessages();
        self::assertSame(2, $this->readModel->find($product->id)?->available, 'a reservation takes a key out of stock');

        $clock->sleep(901);
        self::assertSame(1, $this->infra->expireReservations($clock)(new \App\Inventory\Application\Command\ExpireReservations\ExpireReservationsCommand(10))->expiredCount);
        $this->deliverNewOutboxMessages();
        self::assertSame(3, $this->readModel->find($product->id)?->available, 'an expired reservation returns the key');
    }

    public function testRedeliveredMessageChangesNothing(): void
    {
        $product = $this->seedProduct();
        $this->seedKeys($product->id, 2);
        $this->infra->reserveKey(new MockClock('2026-05-01T10:00:00Z'))(new ReserveKeyCommand(self::uuid(), $product->id->value));

        $publisher = new RecordingOutboxPublisher();
        $this->infra->relay($publisher)->publishBatch(100);

        foreach ($publisher->sent as $message) {
            ($this->projection)($message);
        }

        $this->connection->executeStatement('UPDATE product_availability_view SET available = 99');

        foreach ($publisher->sent as $message) {
            ($this->projection)($message);
        }

        self::assertSame(99, $this->readModel->find($product->id)?->available, 'the inbox swallowed the repeat');
    }

    private function deliverNewOutboxMessages(): void
    {
        $publisher = new RecordingOutboxPublisher();
        $this->infra->relay($publisher)->publishBatch(100);

        foreach ($publisher->sent as $message) {
            ($this->projection)($message);
        }
    }
}
