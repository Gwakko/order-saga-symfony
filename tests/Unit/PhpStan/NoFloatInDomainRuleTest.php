<?php

declare(strict_types=1);

namespace App\Tests\Unit\PhpStan;

use App\PhpStan\Rules\NoFloatInDomainRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * @extends RuleTestCase<NoFloatInDomainRule>
 */
#[Group('T-ARCH-05')]
final class NoFloatInDomainRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoFloatInDomainRule();
    }

    public function testReportsEveryFloatInDomain(): void
    {
        $this->analyse([__DIR__ . '/../../Fixtures/PhpStan/NoFloatInDomain/Violation.php'], [
            [self::message('float property'), 9],
            [self::message('float property'), 11],
            [self::message('float property'), 13],
            [self::message('float parameter or promoted property'), 15],
            [self::message('float parameter or promoted property'), 17],
            [self::message('float return type'), 22],
            [self::message('float literal'), 29],
            [self::message('cast to float'), 34],
            [self::message('float return type'), 41],
        ]);
    }

    public function testReportsFloatInApplication(): void
    {
        $this->analyse([__DIR__ . '/../../Fixtures/PhpStan/NoFloatInDomain/ViolationInApplication.php'], [
            [self::message('float parameter or promoted property'), 9],
        ]);
    }

    public function testAcceptsIntegerMoneyInDomain(): void
    {
        $this->analyse([__DIR__ . '/../../Fixtures/PhpStan/NoFloatInDomain/Valid.php'], []);
    }

    public function testAcceptsFloatOutsideDomainAndApplication(): void
    {
        $this->analyse([__DIR__ . '/../../Fixtures/PhpStan/NoFloatInDomain/ValidInfrastructure.php'], []);
    }

    private static function message(string $what): string
    {
        return ucfirst($what) . ' is forbidden in Domain/Application; use integer minor units (Money).';
    }
}
