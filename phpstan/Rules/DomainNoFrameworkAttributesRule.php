<?php

declare(strict_types=1);

namespace App\PhpStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * T-ARCH-03: Domain and Application stay free of validation, serialization, ORM and Messenger attributes.
 *
 * @implements Rule<Attribute>
 */
final readonly class DomainNoFrameworkAttributesRule implements Rule
{
    /** Attribute namespaces that belong to the infrastructure layer only. */
    private const array FORBIDDEN_NAMESPACES = [
        'Symfony\\Component\\Validator\\Constraints\\',
        'Symfony\\Component\\Serializer\\Attribute\\',
        'Doctrine\\ORM\\Mapping\\',
        'Symfony\\Component\\Messenger\\Attribute\\',
    ];

    /**
     * @param string $guardedNamespacePattern regex (without delimiters) matching the namespaces the rule guards
     */
    public function __construct(private string $guardedNamespacePattern = '^App\\\\[A-Za-z]+\\\\(Domain|Application)(\\\\|$)') {}

    public function getNodeType(): string
    {
        return Attribute::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (preg_match('/' . $this->guardedNamespacePattern . '/', $scope->getNamespace() ?? '') !== 1) {
            return [];
        }

        $name = $node->name->toString() . '\\';

        foreach (self::FORBIDDEN_NAMESPACES as $forbidden) {
            if (str_starts_with(strtolower($name), strtolower($forbidden))) {
                return [
                    RuleErrorBuilder::message(sprintf(
                        'Attribute #[%s] is a framework attribute and is not allowed in Domain/Application; map it in the infrastructure layer.',
                        $node->name->toString(),
                    ))
                        ->identifier('saga.domainFrameworkAttribute')
                        ->build(),
                ];
            }
        }

        return [];
    }
}
