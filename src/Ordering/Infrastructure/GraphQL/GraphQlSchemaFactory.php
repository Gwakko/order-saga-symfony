<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\GraphQL;

use App\Ordering\Application\Query\GetOrder\OrderDetailsOutput;
use App\Ordering\Application\Query\ListCustomerOrders\OrderSummaryOutput;
use GraphQL\Language\Parser;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Schema;
use GraphQL\Utils\BuildSchema;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads `config/graphql/schema.graphql` and attaches the resolvers. The container builds the Schema once per process
 * (a shared service), never per request.
 */
final readonly class GraphQlSchemaFactory
{
    /** Orders per "page unit" in the complexity estimate: a list of 100 rows costs five times a list of 20. */
    private const int COMPLEXITY_PAGE_UNIT = 20;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/graphql/schema.graphql')]
        private string $schemaFile,
        private QueryResolver $query,
        private OrderFieldResolver $order,
    ) {}

    public function create(): Schema
    {
        $sdl = file_get_contents($this->schemaFile);

        if ($sdl === false) {
            throw new \RuntimeException(sprintf('GraphQL schema "%s" is not readable.', $this->schemaFile));
        }

        $schema = BuildSchema::build(Parser::parse($sdl));

        $this->resolve($schema, 'Query', [
            'order' => $this->query->order(...),
            'myOrders' => $this->query->myOrders(...),
            'product' => $this->query->product(...),
        ]);

        foreach (['Order', 'OrderSummary'] as $type) {
            $this->resolve($schema, $type, [
                'status' => fn(object $order) => $this->order->status(self::order($order)),
                'createdAt' => fn(object $order) => $this->order->createdAt(self::order($order)),
                'updatedAt' => fn(object $order) => $this->order->updatedAt(self::order($order)),
                'product' => fn(object $order, mixed $args, GraphQlContext $context) => $this->order->product(self::order($order), $args, $context),
            ]);
        }

        $this->resolve($schema, 'Order', [
            'completedAt' => fn(object $order) => $this->order->completedAt(self::details($order)),
        ]);

        $root = $schema->getType('Query');
        assert($root instanceof ObjectType);
        $root->getField('myOrders')->complexityFn = static function (int $children, array $args): int {
            $limit = $args['limit'] ?? self::COMPLEXITY_PAGE_UNIT;
            $pages = max(1, (int) ceil((is_int($limit) ? $limit : self::COMPLEXITY_PAGE_UNIT) / self::COMPLEXITY_PAGE_UNIT));

            return 1 + $children * $pages;
        };

        $schema->assertValid();

        return $schema;
    }

    /**
     * @param array<string, callable> $resolvers
     */
    private function resolve(Schema $schema, string $typeName, array $resolvers): void
    {
        $type = $schema->getType($typeName);
        assert($type instanceof ObjectType);

        foreach ($resolvers as $field => $resolver) {
            $type->getField($field)->resolveFn = $resolver;
        }
    }

    private static function details(object $order): OrderDetailsOutput
    {
        return $order instanceof OrderDetailsOutput ? $order : throw new \LogicException(sprintf('Unexpected order source %s.', $order::class));
    }

    private static function order(object $order): OrderDetailsOutput|OrderSummaryOutput
    {
        return match (true) {
            $order instanceof OrderDetailsOutput,
            $order instanceof OrderSummaryOutput => $order,
            default => throw new \LogicException(sprintf('Unexpected order source %s.', $order::class)),
        };
    }
}
