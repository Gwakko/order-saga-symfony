<?php

declare(strict_types=1);

namespace App\PhpStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Switch_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * T-ARCH-07: `switch` is forbidden, use `match`.
 *
 * @implements Rule<Switch_>
 */
final readonly class NoSwitchRule implements Rule
{
    /**
     * @param string $onlyPathContaining analyse only files whose path contains this fragment ('' means every file)
     */
    public function __construct(private string $onlyPathContaining = '') {}

    public function getNodeType(): string
    {
        return Switch_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!str_contains($scope->getFile(), $this->onlyPathContaining)) {
            return [];
        }

        return [
            RuleErrorBuilder::message('switch is forbidden, use match.')
                ->identifier('eneba.noSwitch')
                ->build(),
        ];
    }
}
