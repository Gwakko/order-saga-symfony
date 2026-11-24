<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Infrastructure\GraphQL;

use App\Ordering\Infrastructure\GraphQL\GraphQlErrorFormatter;
use App\Shared\Domain\DomainError;
use App\Shared\Domain\DomainErrorCode;
use App\Shared\Infrastructure\Http\ErrorStatusMap;
use GraphQL\Error\Error;
use GraphQL\Language\Source;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Domain/application errors leave GraphQL with the REST `code` in `extensions.code` (M7.1).
 */
#[Group('M7.1')]
final class GraphQlErrorFormatterTest extends TestCase
{
    /**
     * @return iterable<string, array{DomainErrorCode}>
     */
    public static function codes(): iterable
    {
        foreach (DomainErrorCode::cases() as $code) {
            yield $code->value => [$code];
        }
    }

    #[DataProvider('codes')]
    public function testEveryDomainCodeKeepsItsRestCodeAndStatus(DomainErrorCode $code): void
    {
        $error = new Error('wrapped', null, null, [], ['field'], self::failure($code));
        $formatted = (new GraphQlErrorFormatter(new NullLogger()))($error);
        $status = ErrorStatusMap::statusOf($code);

        if ($status >= 500) {
            // A saga-internal code reaching the edge is a defect: nothing of it is shown.
            self::assertSame(['code' => 'INTERNAL_ERROR', 'status' => 500], ($formatted['extensions'] ?? null));
            self::assertSame('Internal server error.', $formatted['message']);

            return;
        }

        self::assertSame(['code' => $code->value, 'status' => $status], ($formatted['extensions'] ?? null));
        self::assertSame('domain failure', $formatted['message']);
        self::assertSame(['field'], $formatted['path'] ?? null);
    }

    public function testHttpExceptionKeepsItsStatus(): void
    {
        $described = (new GraphQlErrorFormatter(new NullLogger()))->describe(new NotFoundHttpException('nope'));

        self::assertSame(['code' => 'NOT_FOUND', 'status' => 404, 'message' => 'nope'], $described);
    }

    public function testErrorsRaisedByTheLibraryAreClientErrors(): void
    {
        $error = new Error('Syntax Error: Expected Name', null, new Source('{ order( '), [2]);
        $formatted = (new GraphQlErrorFormatter(new NullLogger()))($error);

        self::assertSame(['code' => 'GRAPHQL_VALIDATION_FAILED', 'status' => 400], ($formatted['extensions'] ?? null));
        self::assertSame('Syntax Error: Expected Name', $formatted['message']);
        self::assertArrayHasKey('locations', $formatted);
    }

    public function testUnexpectedFailureNeverLeaksItsMessage(): void
    {
        $error = new Error('boom', null, null, [], null, new \RuntimeException('SQLSTATE[08006] password=hunter2'));
        $formatted = (new GraphQlErrorFormatter(new NullLogger()))($error);

        self::assertSame(['code' => 'INTERNAL_ERROR', 'status' => 500], ($formatted['extensions'] ?? null));
        self::assertStringNotContainsString('hunter2', json_encode($formatted, JSON_THROW_ON_ERROR));
    }

    private static function failure(DomainErrorCode $code): DomainError
    {
        return new class ($code) extends DomainError {
            public function __construct(private readonly DomainErrorCode $errorCode)
            {
                parent::__construct('domain failure');
            }

            public function code(): DomainErrorCode
            {
                return $this->errorCode;
            }
        };
    }
}
