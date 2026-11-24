<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final class ValidInfrastructure
{
    #[Assert\NotBlank]
    public string $name = '';
}
