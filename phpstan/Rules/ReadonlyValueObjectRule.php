<?php

declare(strict_types=1);

namespace App\PhpStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * T-ARCH-10: value objects, aggregates, events (everything in `*\Domain`) and DTOs (`*Command`, `*Query`, `*Output`
 * in Application, `*Request` in Infrastructure\Http\Request) are `final readonly`.
 *
 * Built-in exemptions: enums, interfaces, traits, anonymous classes and Throwables (exceptions cannot be readonly).
 * Abstract classes cannot be final, so they must at least be `readonly`.
 *
 * @implements Rule<InClassNode>
 */
final readonly class ReadonlyValueObjectRule implements Rule
{
    /**
     * @param list<class-string|string> $exceptions fully qualified class names that are exempt
     */
    public function __construct(private array $exceptions = []) {}

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();

        if ($class->isAnonymous() || $class->isEnum() || $class->isInterface() || $class->isTrait()) {
            return [];
        }

        $name = $class->getName();

        if (!$this->isGuarded($name) || in_array($name, $this->exceptions, true) || $class->implementsInterface(\Throwable::class)) {
            return [];
        }

        $problem = match (true) {
            !$class->isReadOnly() => 'must be declared readonly',
            !$class->isAbstract() && !$class->isFinal() => 'must be declared final',
            default => null,
        };

        if ($problem === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf('Class %s %s (value objects, domain classes and DTOs are `final readonly`).', $name, $problem))
                ->identifier('eneba.readonlyValueObject')
                ->build(),
        ];
    }

    private function isGuarded(string $className): bool
    {
        return preg_match('/^App\\\\[A-Za-z]+\\\\Domain\\\\/', $className) === 1
            || preg_match('/^App\\\\[A-Za-z]+\\\\Application\\\\.*(Command|Query|Output)$/', $className) === 1
            || preg_match('/^App\\\\[A-Za-z]+\\\\Infrastructure\\\\Http\\\\Request\\\\.*Request$/', $className) === 1;
    }
}
