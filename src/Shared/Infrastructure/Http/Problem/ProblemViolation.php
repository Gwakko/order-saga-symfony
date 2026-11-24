<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Problem;

final readonly class ProblemViolation
{
    public function __construct(public string $propertyPath, public string $message) {}
}
