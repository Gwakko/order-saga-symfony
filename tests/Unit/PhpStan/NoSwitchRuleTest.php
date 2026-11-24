<?php

declare(strict_types=1);

namespace App\Tests\Unit\PhpStan;

use App\PhpStan\Rules\NoSwitchRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * @extends RuleTestCase<NoSwitchRule>
 */
#[Group('T-ARCH-07')]
final class NoSwitchRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoSwitchRule();
    }

    public function testReportsSwitch(): void
    {
        $this->analyse([__DIR__ . '/../../Fixtures/PhpStan/NoSwitch/Violation.php'], [
            ['switch is forbidden, use match.', 11],
        ]);
    }

    public function testAcceptsMatch(): void
    {
        $this->analyse([__DIR__ . '/../../Fixtures/PhpStan/NoSwitch/Valid.php'], []);
    }
}
