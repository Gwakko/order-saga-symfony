<?php

declare(strict_types=1);

namespace App\PhpStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\Declare_;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * T-ARCH-06: every analysed file must start with declare(strict_types=1).
 *
 * @implements Rule<FileNode>
 */
final class StrictTypesDeclaredRule implements Rule
{
    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $statements = $node->getNodes();
        if ($statements === []) {
            return [];
        }

        foreach ($statements as $statement) {
            if ($statement instanceof Declare_ && $this->declaresStrictTypes($statement)) {
                return [];
            }
        }

        return [
            RuleErrorBuilder::message('File must declare strict_types=1.')
                ->identifier('eneba.strictTypesMissing')
                ->line($statements[0]->getStartLine())
                ->build(),
        ];
    }

    private function declaresStrictTypes(Declare_ $declare): bool
    {
        foreach ($declare->declares as $item) {
            if ($item->key->toString() === 'strict_types' && $item->value instanceof Int_ && $item->value->value === 1) {
                return true;
            }
        }

        return false;
    }
}
