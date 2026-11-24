<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

final class NotReadonly
{
    public function __construct(public readonly int $value) {}
}

readonly class NotFinal
{
    public function __construct(public int $value) {}
}

abstract class AbstractNotReadonly {}

class Plain {}
