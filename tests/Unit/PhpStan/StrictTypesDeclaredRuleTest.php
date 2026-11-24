<?php

declare(strict_types=1);

namespace App\Tests\Unit\PhpStan;

use App\PhpStan\Rules\StrictTypesDeclaredRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * @extends RuleTestCase<StrictTypesDeclaredRule>
 */
#[Group('T-ARCH-06')]
final class StrictTypesDeclaredRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new StrictTypesDeclaredRule();
    }

    public function testReportsFileWithoutStrictTypes(): void
    {
        $this->analyse([__DIR__ . '/../../Fixtures/PhpStan/StrictTypes/Violation.php'], [
            ['File must declare strict_types=1.', 3],
        ]);
    }

    public function testAcceptsFileWithStrictTypes(): void
    {
        $this->analyse([__DIR__ . '/../../Fixtures/PhpStan/StrictTypes/Valid.php'], []);
    }
}
