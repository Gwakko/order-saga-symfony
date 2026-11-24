<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\MarkOrderPaid;

final readonly class MarkOrderPaidOutput
{
    public function __construct(public int $version) {}
}
