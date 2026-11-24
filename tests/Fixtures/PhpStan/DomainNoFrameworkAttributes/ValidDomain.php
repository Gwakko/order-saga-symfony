<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

final readonly class ValidDomain
{
    #[\SensitiveParameter]
    public string $secret;

    public function __construct(string $secret)
    {
        $this->secret = $secret;
    }
}
