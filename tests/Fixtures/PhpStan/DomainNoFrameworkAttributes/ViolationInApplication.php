<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command;

use Symfony\Component\Validator\Constraints\Positive;

final class ViolationInApplication
{
    #[Positive]
    public int $amount = 1;
}
