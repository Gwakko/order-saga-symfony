<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Hello;

final readonly class HelloActivityImpl implements HelloActivity
{
    public function __construct(private Greeter $greeter) {}

    public function greet(string $name): string
    {
        return $this->greeter->greet($name);
    }
}
