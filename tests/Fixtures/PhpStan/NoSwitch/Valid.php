<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\PhpStan\NoSwitch;

final class Valid
{
    public function label(int $status): string
    {
        return match ($status) {
            1 => 'one',
            default => 'other',
        };
    }
}
