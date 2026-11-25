<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\GraphQL;

use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\Node;
use GraphQL\Validator\Rules\QueryDepth;

/**
 * The depth limit of EC-82, except that `__schema` and `__type` subtrees do not count when introspection is switched
 * on (dev/test): the full query of GraphiQL is depth 11 because `TypeRef` nests `ofType` seven times. Where
 * introspection is off the rule is the plain `QueryDepth`, and `DisableIntrospection` refuses those fields anyway.
 */
final class QueryDepthExceptIntrospection extends QueryDepth
{
    public function __construct(int $maxQueryDepth, private readonly bool $skipIntrospection)
    {
        parent::__construct($maxQueryDepth);
    }

    protected function nodeDepth(Node $node, int $depth = 0, int $maxDepth = 0): int
    {
        if ($this->skipIntrospection && $node instanceof FieldNode && in_array($node->name->value, ['__schema', '__type'], true)) {
            return $maxDepth;
        }

        return parent::nodeDepth($node, $depth, $maxDepth);
    }
}
