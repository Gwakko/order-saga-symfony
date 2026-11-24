<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\MarkOrderKeyReserved;

final readonly class MarkOrderKeyReservedOutput
{
    public function __construct(public int $version) {}
}
