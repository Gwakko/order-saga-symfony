<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Infrastructure\GraphQL;

use App\Ordering\Infrastructure\GraphQL\GraphQlValidationRules;
use GraphQL\Language\Parser;
use GraphQL\Utils\BuildSchema;
use GraphQL\Validator\DocumentValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('M7.2')]
#[Group('T-F-30')]
final class GraphQlValidationRulesTest extends TestCase
{
    private const string INTROSPECTION = '{ __schema { queryType { name } } }';

    public function testIntrospectionIsRefusedWhenSwitchedOff(): void
    {
        $errors = $this->validate(self::INTROSPECTION, new GraphQlValidationRules(false));

        self::assertCount(1, $errors);
        self::assertStringContainsString('introspection is not allowed', strtolower($errors[0]));
    }

    public function testIntrospectionPassesWhenSwitchedOn(): void
    {
        self::assertSame([], $this->validate(self::INTROSPECTION, new GraphQlValidationRules(true)));
    }

    public function testRulesAreFreshForEveryRequest(): void
    {
        $rules = new GraphQlValidationRules(true);
        $first = $rules->create();
        $second = $rules->create();

        // The depth, complexity and introspection rules keep the state of one document.
        self::assertNotSame(end($first), end($second));
        self::assertNotSame($first[count($first) - 3], $second[count($second) - 3]);
    }

    /**
     * @return list<string>
     */
    private function validate(string $query, GraphQlValidationRules $rules): array
    {
        $schema = BuildSchema::build('type Query { ping: String }');

        return array_map(
            static fn(\GraphQL\Error\Error $error): string => $error->getMessage(),
            DocumentValidator::validate($schema, Parser::parse($query), $rules->create()),
        );
    }
}
