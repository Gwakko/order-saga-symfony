<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\PhpStan\NoSwitch;

final class Violation
{
    public function label(int $status): string
    {
        switch ($status) {
            case 1:
                return 'one';
            default:
                return 'other';
        }
    }
}
