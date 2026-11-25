<?php

declare(strict_types=1);

namespace App\PhpStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\Cast\Double;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\UnionType;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * T-ARCH-05: money is an integer in minor units. Domain and Application must not declare `float` (parameters,
 * properties incl. promoted ones, return types), use float literals or cast to float.
 *
 * @implements Rule<Node>
 */
final readonly class NoFloatInDomainRule implements Rule
{
    /**
     * @param string $guardedNamespacePattern regex (without delimiters) matching the namespaces the rule guards
     */
    public function __construct(private string $guardedNamespacePattern = '^App\\\\[A-Za-z]+\\\\(Domain|Application)(\\\\|$)') {}

    public function getNodeType(): string
    {
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (preg_match('/' . $this->guardedNamespacePattern . '/', $scope->getNamespace() ?? '') !== 1) {
            return [];
        }

        $problem = match (true) {
            $node instanceof Param => $this->containsFloat($node->type) ? 'float parameter or promoted property' : null,
            $node instanceof Property => $this->containsFloat($node->type) ? 'float property' : null,
            $node instanceof FunctionLike => $this->containsFloat($node->getReturnType()) ? 'float return type' : null,
            $node instanceof Float_ => 'float literal',
            $node instanceof Double => 'cast to float',
            default => null,
        };

        if ($problem === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf('%s is forbidden in Domain/Application; use integer minor units (Money).', ucfirst($problem)))
                ->identifier('saga.noFloatInDomain')
                ->line($node->getStartLine())
                ->build(),
        ];
    }

    private function containsFloat(?Node $type): bool
    {
        return match (true) {
            $type instanceof Identifier => strtolower($type->name) === 'float',
            $type instanceof NullableType => $this->containsFloat($type->type),
            $type instanceof UnionType, $type instanceof IntersectionType => array_any(
                $type->types,
                fn(Node $inner): bool => $this->containsFloat($inner),
            ),
            default => false,
        };
    }
}
