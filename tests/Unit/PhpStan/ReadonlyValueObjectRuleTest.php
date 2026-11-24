<?php

declare(strict_types=1);

namespace App\Tests\Unit\PhpStan;

use App\PhpStan\Rules\ReadonlyValueObjectRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * @extends RuleTestCase<ReadonlyValueObjectRule>
 */
#[Group('T-ARCH-10')]
final class ReadonlyValueObjectRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new ReadonlyValueObjectRule(['App\\Ordering\\Domain\\ListedException']);
    }

    public function testReportsDomainClassesThatAreNotFinalReadonly(): void
    {
        $this->analyse([__DIR__ . '/../../Fixtures/PhpStan/ReadonlyValueObject/Violation.php'], [
            [self::message('App\\Ordering\\Domain\\NotReadonly', 'must be declared readonly'), 7],
            [self::message('App\\Ordering\\Domain\\NotFinal', 'must be declared final'), 12],
            [self::message('App\\Ordering\\Domain\\AbstractNotReadonly', 'must be declared readonly'), 17],
            [self::message('App\\Ordering\\Domain\\Plain', 'must be declared readonly'), 19],
        ]);
    }

    public function testReportsApplicationDtos(): void
    {
        $this->analyse([__DIR__ . '/../../Fixtures/PhpStan/ReadonlyValueObject/ViolationDtos.php'], [
            [self::message('App\\Inventory\\Application\\Command\\AddKeys\\AddKeysCommand', 'must be declared readonly'), 7],
            [self::message('App\\Inventory\\Application\\Command\\AddKeys\\AddKeysOutput', 'must be declared readonly'), 14],
        ]);
    }

    public function testReportsHttpRequestDtos(): void
    {
        $this->analyse([__DIR__ . '/../../Fixtures/PhpStan/ReadonlyValueObject/ViolationRequest.php'], [
            [self::message('App\\Ordering\\Infrastructure\\Http\\Request\\PlaceOrderRequest', 'must be declared readonly'), 7],
        ]);
    }

    public function testAcceptsEnumsInterfacesTraitsExceptionsAndListedClasses(): void
    {
        $this->analyse([__DIR__ . '/../../Fixtures/PhpStan/ReadonlyValueObject/Valid.php'], []);
    }

    public function testIgnoresHandlersAndUnguardedNamespaces(): void
    {
        $this->analyse([__DIR__ . '/../../Fixtures/PhpStan/ReadonlyValueObject/ValidOutsideGuardedLayers.php'], []);
    }

    private static function message(string $class, string $problem): string
    {
        return sprintf('Class %s %s (value objects, domain classes and DTOs are `final readonly`).', $class, $problem);
    }
}
