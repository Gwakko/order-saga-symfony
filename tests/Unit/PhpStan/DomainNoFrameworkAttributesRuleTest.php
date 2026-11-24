<?php

declare(strict_types=1);

namespace App\Tests\Unit\PhpStan;

use App\PhpStan\Rules\DomainNoFrameworkAttributesRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * @extends RuleTestCase<DomainNoFrameworkAttributesRule>
 */
#[Group('T-ARCH-03')]
final class DomainNoFrameworkAttributesRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new DomainNoFrameworkAttributesRule();
    }

    public function testReportsFrameworkAttributesInDomain(): void
    {
        $this->analyse([__DIR__ . '/../../Fixtures/PhpStan/DomainNoFrameworkAttributes/Violation.php'], [
            [self::message('Doctrine\\ORM\\Mapping\\Entity'), 12],
            [self::message('Symfony\\Component\\Messenger\\Attribute\\AsMessageHandler'), 13],
            [self::message('Symfony\\Component\\Validator\\Constraints\\NotBlank'), 16],
            [self::message('Symfony\\Component\\Serializer\\Attribute\\Groups'), 17],
        ]);
    }

    public function testReportsFrameworkAttributesInApplication(): void
    {
        $this->analyse([__DIR__ . '/../../Fixtures/PhpStan/DomainNoFrameworkAttributes/ViolationInApplication.php'], [
            [self::message('Symfony\\Component\\Validator\\Constraints\\Positive'), 11],
        ]);
    }

    public function testAcceptsPlainPhpAttributesInDomain(): void
    {
        $this->analyse([__DIR__ . '/../../Fixtures/PhpStan/DomainNoFrameworkAttributes/ValidDomain.php'], []);
    }

    public function testAcceptsFrameworkAttributesInInfrastructure(): void
    {
        $this->analyse([__DIR__ . '/../../Fixtures/PhpStan/DomainNoFrameworkAttributes/ValidInfrastructure.php'], []);
    }

    private static function message(string $attribute): string
    {
        return sprintf('Attribute #[%s] is a framework attribute and is not allowed in Domain/Application; map it in the infrastructure layer.', $attribute);
    }
}
