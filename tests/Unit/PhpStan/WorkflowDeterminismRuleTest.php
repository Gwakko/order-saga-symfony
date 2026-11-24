<?php

declare(strict_types=1);

namespace App\Tests\Unit\PhpStan;

use App\PhpStan\Rules\WorkflowDeterminismRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * @extends RuleTestCase<WorkflowDeterminismRule>
 */
#[Group('T-ARCH-09')]
final class WorkflowDeterminismRuleTest extends RuleTestCase
{
    private const string SUFFIX = ' is not allowed in workflow code: a replay must take the same decisions (use Workflow::now(), Workflow::uuid() or an activity).';

    protected function getRule(): Rule
    {
        return new WorkflowDeterminismRule();
    }

    public function testReportsNonDeterministicCodeInAWorkflowClass(): void
    {
        $this->analyse([__DIR__ . '/../../Fixtures/PhpStan/WorkflowDeterminism/Violation.php'], [
            ['the class Doctrine\\DBAL\\Connection' . self::SUFFIX, 15],
            ['time()' . self::SUFFIX, 17],
            ['microtime()' . self::SUFFIX, 18],
            ['new DateTimeImmutable() (the system clock)' . self::SUFFIX, 19],
            ['new DateTime() (the system clock)' . self::SUFFIX, 20],
            ['random_int()' . self::SUFFIX, 21],
            ['rand()' . self::SUFFIX, 22],
            ['uniqid()' . self::SUFFIX, 23],
            ['sleep()' . self::SUFFIX, 24],
            ['usleep()' . self::SUFFIX, 25],
            ['getenv()' . self::SUFFIX, 26],
            ['file_get_contents()' . self::SUFFIX, 27],
            ['the static call Ramsey\\Uuid\\Uuid::uuid4()' . self::SUFFIX, 28],
            ['a static property' . self::SUFFIX, 29],
            ['the class PDO' . self::SUFFIX, 31],
        ]);
    }

    public function testReportsNonDeterministicCodeInAClassWhoseInterfaceIsAWorkflow(): void
    {
        $this->analyse([__DIR__ . '/../../Fixtures/PhpStan/WorkflowDeterminism/ViolationViaInterface.php'], [
            ['time()' . self::SUFFIX, 11],
        ]);
    }

    public function testAcceptsDeterministicWorkflowCode(): void
    {
        $this->analyse([__DIR__ . '/../../Fixtures/PhpStan/WorkflowDeterminism/Valid.php'], []);
    }

    public function testIgnoresClassesThatAreNotWorkflows(): void
    {
        $this->analyse([__DIR__ . '/../../Fixtures/PhpStan/WorkflowDeterminism/OutsideWorkflow.php'], []);
    }
}
