<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Hello;

use Psr\Clock\ClockInterface;

/** Spike service: proves that an activity reaches a service from the Symfony container (here: the PSR-20 clock). */
final readonly class Greeter
{
    public function __construct(private ClockInterface $clock) {}

    public function greet(string $name): string
    {
        return sprintf('Hello, %s! (from the Symfony container at %s)', $name, $this->clock->now()->format('c'));
    }
}
