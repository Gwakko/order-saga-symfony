<?php

declare(strict_types=1);

namespace App\PhpStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * T-ARCH-08: system clock access is allowed only in `*\Infrastructure\Clock`.
 *
 * @implements Rule<Node\Expr>
 */
final class NoSystemClockRule implements Rule
{
    /** Functions that always read the system clock. */
    private const array ALWAYS_FORBIDDEN_FUNCTIONS = ['time', 'date', 'microtime', 'hrtime'];

    /** Functions that read the system clock when called without arguments or with 'now'. */
    private const array NOW_BY_DEFAULT_FUNCTIONS = ['date_create', 'date_create_immutable'];

    private const array DATE_CLASSES = ['datetime', 'datetimeimmutable'];

    public function getNodeType(): string
    {
        return Node\Expr::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (str_contains($scope->getNamespace() ?? '', '\\Infrastructure\\Clock')) {
            return [];
        }

        $violation = match (true) {
            $node instanceof FuncCall => $this->checkFunctionCall($node, $scope),
            $node instanceof New_ => $this->checkNew($node, $scope),
            default => null,
        };

        if ($violation === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message($violation . ' reads the system clock; use ClockInterface or pass the time as an argument.')
                ->identifier('saga.noSystemClock')
                ->build(),
        ];
    }

    private function checkFunctionCall(FuncCall $call, Scope $scope): ?string
    {
        if (!$call->name instanceof Name || $call->name->isQualified()) {
            return null;
        }

        $name = strtolower($call->name->toString());

        return match (true) {
            \in_array($name, self::ALWAYS_FORBIDDEN_FUNCTIONS, true) => $name . '()',
            \in_array($name, self::NOW_BY_DEFAULT_FUNCTIONS, true) && $this->isNowArgument($call->getArgs(), $scope) => $name . '()',
            default => null,
        };
    }

    private function checkNew(New_ $new, Scope $scope): ?string
    {
        if (!$new->class instanceof Name) {
            return null;
        }

        $class = ltrim(strtolower($new->class->toString()), '\\');
        if (!\in_array($class, self::DATE_CLASSES, true) || !$this->isNowArgument($new->getArgs(), $scope)) {
            return null;
        }

        return 'new ' . $new->class->toString() . '()';
    }

    /**
     * @param array<Node\Arg> $args
     */
    private function isNowArgument(array $args, Scope $scope): bool
    {
        if ($args === []) {
            return true;
        }

        $strings = $scope->getType($args[0]->value)->getConstantStrings();
        if ($strings === []) {
            return false;
        }

        foreach ($strings as $string) {
            if (strtolower(trim($string->getValue())) !== 'now') {
                return false;
            }
        }

        return true;
    }
}
