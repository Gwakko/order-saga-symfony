<?php

declare(strict_types=1);

namespace App\Tests\Unit\PhpStan;

use App\PhpStan\Rules\NoSystemClockRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * @extends RuleTestCase<NoSystemClockRule>
 */
#[Group('T-ARCH-08')]
final class NoSystemClockRuleTest extends RuleTestCase
{
    private const string SUFFIX = ' reads the system clock; use ClockInterface or pass the time as an argument.';

    protected function getRule(): Rule
    {
        return new NoSystemClockRule();
    }

    public function testReportsSystemClockAccess(): void
    {
        $this->analyse([__DIR__ . '/../../Fixtures/PhpStan/NoSystemClock/Violation.php'], [
            ['time()' . self::SUFFIX, 11],
            ['date()' . self::SUFFIX, 12],
            ['microtime()' . self::SUFFIX, 13],
            ['hrtime()' . self::SUFFIX, 14],
            ['new DateTimeImmutable()' . self::SUFFIX, 15],
            ['new DateTime()' . self::SUFFIX, 16],
            ['date_create()' . self::SUFFIX, 17],
        ]);
    }

    public function testAcceptsExplicitTime(): void
    {
        $this->analyse([__DIR__ . '/../../Fixtures/PhpStan/NoSystemClock/Valid.php'], []);
    }

    public function testAllowsClockInfrastructureNamespace(): void
    {
        $this->analyse([__DIR__ . '/../../Fixtures/PhpStan/NoSystemClock/Infrastructure/SystemClock.php'], []);
    }
}
