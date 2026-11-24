<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\GraphQL;

use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\DisableIntrospection;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\QueryDepth;
use GraphQL\Validator\Rules\ValidationRule;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Standard validation plus the limits of EC-82 and, outside development, no introspection. The rules keep state of
 * one document, so a fresh set is made for every request.
 */
final readonly class GraphQlValidationRules
{
    public const int MAX_DEPTH = 8;
    public const int MAX_COMPLEXITY = 200;

    public function __construct(#[Autowire('%graphql.introspection_enabled%')] private bool $introspectionEnabled) {}

    /**
     * @return list<ValidationRule>
     */
    public function create(): array
    {
        return [
            ...array_values(DocumentValidator::defaultRules()),
            new QueryDepth(self::MAX_DEPTH),
            new QueryComplexity(self::MAX_COMPLEXITY),
            // The constant names read backwards: ENABLED switches the *rule* on, which forbids `__schema` and `__type`.
            new DisableIntrospection($this->introspectionEnabled ? DisableIntrospection::DISABLED : DisableIntrospection::ENABLED),
        ];
    }
}
