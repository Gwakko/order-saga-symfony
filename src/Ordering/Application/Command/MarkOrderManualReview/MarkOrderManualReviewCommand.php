<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\MarkOrderManualReview;

use App\Shared\Domain\FailureReason;

final readonly class MarkOrderManualReviewCommand
{
    public function __construct(public string $orderId, public FailureReason $reason, public string $step) {}
}
