<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Hello;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'Hello.')]
interface HelloActivity
{
    #[ActivityMethod(name: 'greet')]
    public function greet(string $name): string;
}
