<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Infrastructure\GraphQL;

use App\Inventory\Application\Query\GetProductAvailability\ProductAvailabilityOutput;
use App\Inventory\Application\Query\GetProductsAvailability\GetProductsAvailabilityHandler;
use App\Inventory\Application\Query\GetProductsAvailability\GetProductsAvailabilityQuery;
use App\Ordering\Infrastructure\GraphQL\ProductBatchLoader;
use App\Shared\Application\Output\MoneyOutput;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Infrastructure\Bus\QueryBus;
use App\Tests\Support\Fake\InMemoryProductReadModel;
use GraphQL\Executor\Promise\Adapter\SyncPromise;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

/**
 * EC-84 at the port: however many `load()` calls a response makes, the use case runs once per batch.
 */
#[Group('M7.1')]
#[Group('T-F-32')]
final class ProductBatchLoaderTest extends TestCase
{
    private const string A = '0190a5c4-0000-7000-8000-00000000000a';
    private const string B = '0190a5c4-0000-7000-8000-00000000000b';
    private const string MISSING = '0190a5c4-0000-7000-8000-00000000000c';

    private int $batches = 0;

    /** @var list<list<string>> */
    private array $asked = [];

    private function loader(): ProductBatchLoader
    {
        $read = new InMemoryProductReadModel();

        foreach ([self::A, self::B] as $id) {
            $read->put(new ProductAvailabilityOutput($id, 'Game ' . $id, new MoneyOutput(100, 'EUR'), 1));
        }

        $handler = new GetProductsAvailabilityHandler($read);
        $counting = function (GetProductsAvailabilityQuery $query) use ($handler) {
            ++$this->batches;
            $this->asked[] = $query->productIds;

            return $handler($query);
        };

        return new ProductBatchLoader(new QueryBus(new MessageBus([
            new HandleMessageMiddleware(new HandlersLocator([GetProductsAvailabilityQuery::class => [$counting]])),
        ])));
    }

    public function testManyLoadsMakeOneBatchOfDistinctIds(): void
    {
        $loader = $this->loader();
        $values = [
            $loader->load(self::A),
            $loader->load(self::B),
            $loader->load(self::A),
            $loader->load(self::MISSING),
        ];

        SyncPromise::runQueue();

        self::assertSame(1, $this->batches);
        self::assertSame([[self::A, self::B, self::MISSING]], $this->asked);
        self::assertInstanceOf(ProductAvailabilityOutput::class, $values[0]->result);
        self::assertInstanceOf(ProductAvailabilityOutput::class, $values[1]->result);
        self::assertSame('Game ' . self::B, $values[1]->result->name);
        self::assertSame($values[0]->result, $values[2]->result);
        self::assertNull($values[3]->result, 'an unknown product is null, not an error');
    }

    public function testAnAlreadyLoadedIdIsNotAskedAgain(): void
    {
        $loader = $this->loader();
        $first = $loader->load(self::A);
        SyncPromise::runQueue();
        $second = $loader->load(self::A);
        SyncPromise::runQueue();

        self::assertSame(1, $this->batches);
        self::assertSame($first->result, $second->result);
    }
}
