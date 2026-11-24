<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Idempotency;

/**
 * Marks a controller whose POST requires an `Idempotency-Key` and replays stored responses (02_use_cases.md section 5).
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class Idempotent {}
