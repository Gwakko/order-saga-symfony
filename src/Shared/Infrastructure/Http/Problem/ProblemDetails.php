<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Problem;

use App\Shared\Application\Output\MoneyOutput;

/**
 * RFC 9457 problem. `code` is the stable machine-readable error code (02_use_cases.md section 6); `errors` lists
 * field violations; `currentPrice` accompanies PRICE_CHANGED (EC-67).
 */
final readonly class ProblemDetails
{
    public const string MEDIA_TYPE = 'application/problem+json';

    /**
     * @param list<ProblemViolation> $errors
     */
    public function __construct(
        public string $type,
        public string $title,
        public int $status,
        public string $code,
        public ?string $detail = null,
        public array $errors = [],
        public ?MoneyOutput $currentPrice = null,
    ) {}
}
