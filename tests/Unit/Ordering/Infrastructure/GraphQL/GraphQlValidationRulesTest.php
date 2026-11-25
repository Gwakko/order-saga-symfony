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

    #[Group('T-M8A-01')]
    public function testFullGraphiQlIntrospectionQueryPassesWhenIntrospectionIsOn(): void
    {
        $query = \GraphQL\Type\Introspection::getIntrospectionQuery();

        self::assertSame([], $this->validate($query, new GraphQlValidationRules(true)));
    }

    #[Group('T-M8A-01')]
    public function testDepthLimitStillAppliesToRegularFieldsWhenIntrospectionIsOn(): void
    {
        $rules = new GraphQlValidationRules(true);

        // node (0) > child x8 = depth 8: allowed; one more = 9: rejected.
        self::assertSame([], $this->validate(self::deep(8), $rules));

        $errors = $this->validate(self::deep(9), $rules);
        self::assertCount(1, $errors);
        self::assertStringContainsString('Max query depth should be 8 but got 9', $errors[0]);
    }

    #[Group('T-M8A-01')]
    public function testDeepRegularQueryCannotHideBesideIntrospection(): void
    {
        $query = '{ __schema { queryType { name } } ' . substr(self::deep(9), 2);
        $errors = $this->validate($query, new GraphQlValidationRules(true));

        self::assertCount(1, $errors);
        self::assertStringContainsString('Max query depth should be 8 but got 9', $errors[0]);
    }

    #[Group('T-M8A-01')]
    public function testIntrospectionIsNotExemptedFromDepthWhenSwitchedOff(): void
    {
        $errors = $this->validate(\GraphQL\Type\Introspection::getIntrospectionQuery(), new GraphQlValidationRules(false));

        self::assertStringContainsString('Max query depth should be 8', implode("\n", $errors));
        self::assertStringContainsString('introspection is not allowed', strtolower(implode("\n", $errors)));
    }

    private static function deep(int $childLevels): string
    {
        $inner = '{ name }';

        for ($i = 0; $i < $childLevels; ++$i) {
            $inner = sprintf('{ child %s }', $inner);
        }

        return sprintf('{ node %s }', $inner);
    }

    /**
     * @return list<string>
     */
    private function validate(string $query, GraphQlValidationRules $rules): array
    {
        $schema = BuildSchema::build('type Query { ping: String node: Node } type Node { name: String child: Node }');

        return array_map(
            static fn(\GraphQL\Error\Error $error): string => $error->getMessage(),
            DocumentValidator::validate($schema, Parser::parse($query), $rules->create()),
        );
    }
}
