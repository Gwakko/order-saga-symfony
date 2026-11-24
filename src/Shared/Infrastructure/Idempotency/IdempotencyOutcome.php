<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Idempotency;

enum IdempotencyOutcome
{
    /** The row was inserted as `in_progress`: the caller executes the request. */
    case Started;

    /** The same request completed earlier: the caller answers with the stored response. */
    case Replay;

    /** The same request is still running elsewhere. */
    case InProgress;

    /** The key belongs to a different request (method, path or body differ). */
    case KeyReused;
}
