<?php

declare(strict_types=1);

namespace App\PhpStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * T-ARCH-09: workflow code must be deterministic, because Temporal replays it from the history.
 *
 * Applies to a class that carries `#[WorkflowInterface]` (directly or through an interface it implements) and to any
 * class in a `...\Saga\Infrastructure\Temporal\Workflow` namespace. Forbidden there: the system clock, randomness,
 * `uniqid`, sleeping, environment and file access, network and database classes, static properties, and static calls
 * outside the allowed list (`Temporal\*`, `Carbon\*`, `self`/`static`/`parent`, the configuration holder and the pure `GatewayInspection` contract mapping).
 * Time comes from `Workflow::now()`, ids from `Workflow::uuid()`, effects from activities.
 *
 * @implements Rule<Node>
 */
final class WorkflowDeterminismRule implements Rule
{
    private const string WORKFLOW_ATTRIBUTE = 'Temporal\\Workflow\\WorkflowInterface';
    private const string WORKFLOW_NAMESPACE = '\\Saga\\Infrastructure\\Temporal\\Workflow';

    /** Functions that read the clock, produce randomness, wait, or touch the environment, files or the network. */
    private const array FORBIDDEN_FUNCTIONS = [
        'time', 'date', 'gmdate', 'microtime', 'hrtime', 'mktime', 'gmmktime', 'date_create', 'date_create_immutable', 'strtotime',
        'rand', 'mt_rand', 'random_int', 'random_bytes', 'mt_srand', 'srand', 'lcg_value', 'uniqid', 'shuffle', 'array_rand', 'str_shuffle',
        'sleep', 'usleep', 'time_nanosleep', 'time_sleep_until',
        'getenv', 'putenv',
        'fopen', 'fread', 'fwrite', 'fgets', 'file', 'readfile', 'unlink', 'mkdir', 'rmdir', 'rename', 'copy', 'touch', 'glob', 'scandir', 'opendir', 'tempnam',
        'fsockopen', 'stream_socket_client', 'socket_create', 'mail',
    ];

    /** A function whose name starts with one of these is forbidden too (`file_get_contents`, `curl_exec`, ...). */
    private const array FORBIDDEN_FUNCTION_PREFIXES = ['file_', 'curl_', 'stream_', 'pg_', 'mysqli_', 'redis_'];

    /** Classes whose mere use means a database, the network or randomness. A name matches by prefix. */
    private const array FORBIDDEN_CLASS_PREFIXES = [
        'PDO', 'Doctrine\\', 'Symfony\\Contracts\\HttpClient\\', 'Symfony\\Component\\HttpClient\\', 'Psr\\Http\\Client\\',
        'GuzzleHttp\\', 'Redis', 'Random\\',
    ];

    /**
     * Static calls on these classes are fine. The `GatewayInspection` contract classes are pure mappings between arrays and
     * value objects (no clock, no randomness, no I/O; docs/spec/09_go_worker.md section 8).
     */
    private const array ALLOWED_STATIC_CLASS_PREFIXES = [
        'Temporal\\', 'Carbon\\', 'App\\Saga\\Infrastructure\\Temporal\\Config\\SagaConfigurationHolder',
        'App\\Saga\\Infrastructure\\Temporal\\Contract\\GatewayInspection\\',
    ];

    private const array DATE_CLASSES = ['datetime', 'datetimeimmutable'];

    /** @var array<string, bool> */
    private array $workflowClasses = [];

    public function getNodeType(): string
    {
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $violation = match (true) {
            $node instanceof FuncCall => $this->checkFunction($node, $scope),
            $node instanceof New_ => $this->checkNew($node, $scope),
            $node instanceof StaticCall => $this->checkStaticCall($node),
            $node instanceof StaticPropertyFetch => 'a static property',
            $node instanceof Name\FullyQualified => $this->checkClassName($node),
            default => null,
        };

        if ($violation === null || !$this->isWorkflowScope($scope)) {
            return [];
        }

        return [
            RuleErrorBuilder::message($violation . ' is not allowed in workflow code: a replay must take the same decisions (use Workflow::now(), Workflow::uuid() or an activity).')
                ->identifier('saga.workflowDeterminism')
                ->build(),
        ];
    }

    private function checkFunction(FuncCall $call, Scope $scope): ?string
    {
        if (!$call->name instanceof Name) {
            return null;
        }

        $name = strtolower($call->name->toString());
        $name = ltrim($name, '\\');

        $forbidden = \in_array($name, self::FORBIDDEN_FUNCTIONS, true);
        foreach (self::FORBIDDEN_FUNCTION_PREFIXES as $prefix) {
            $forbidden = $forbidden || str_starts_with($name, $prefix);
        }

        return $forbidden ? $name . '()' : null;
    }

    private function checkNew(New_ $new, Scope $scope): ?string
    {
        if (!$new->class instanceof Name) {
            return null;
        }

        if ($new->class instanceof Name\FullyQualified && ($forbidden = $this->checkClassName($new->class)) !== null) {
            return $forbidden;
        }

        $class = ltrim(strtolower($new->class->toString()), '\\');
        if (!\in_array($class, self::DATE_CLASSES, true)) {
            return null;
        }

        $args = $new->getArgs();
        $isNow = $args === [] || $this->isNowString($scope, $args[0]->value);

        return $isNow ? 'new ' . $new->class->toString() . '() (the system clock)' : null;
    }

    private function isNowString(Scope $scope, Node\Expr $expression): bool
    {
        $strings = $scope->getType($expression)->getConstantStrings();
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

    private function checkStaticCall(StaticCall $call): ?string
    {
        if (!$call->class instanceof Name) {
            return 'a static call on a dynamic class';
        }

        if (\in_array(strtolower($call->class->toString()), ['self', 'static', 'parent'], true)) {
            return null;
        }

        $class = $call->class->toString();
        foreach (self::ALLOWED_STATIC_CLASS_PREFIXES as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return null;
            }
        }

        $method = $call->name instanceof Node\Identifier ? $call->name->toString() : '?';

        return sprintf('the static call %s::%s()', $class, $method);
    }

    private function checkClassName(Name\FullyQualified $name): ?string
    {
        $class = $name->toString();

        foreach (self::FORBIDDEN_CLASS_PREFIXES as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return 'the class ' . $class;
            }
        }

        return null;
    }

    private function isWorkflowScope(Scope $scope): bool
    {
        $class = $scope->getClassReflection();
        if ($class === null) {
            return false;
        }

        return $this->workflowClasses[$class->getName()] ??= $this->isWorkflowClass($class, $scope);
    }

    private function isWorkflowClass(ClassReflection $class, Scope $scope): bool
    {
        if (str_contains($scope->getNamespace() ?? '', self::WORKFLOW_NAMESPACE)) {
            return true;
        }

        if ($class->getNativeReflection()->getAttributes(self::WORKFLOW_ATTRIBUTE) !== []) {
            return true;
        }

        foreach ($class->getInterfaces() as $interface) {
            if ($interface->getNativeReflection()->getAttributes(self::WORKFLOW_ATTRIBUTE) !== []) {
                return true;
            }
        }

        return false;
    }
}
