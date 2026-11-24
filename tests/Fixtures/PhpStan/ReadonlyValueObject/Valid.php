<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

enum Status
{
    case One;
}

interface Contract {}

trait Behaviour {}

final readonly class Valid
{
    public function __construct(public int $value) {}
}

abstract readonly class AbstractValid {}

abstract class DomainFailure extends \DomainException {}

final class SpecificFailure extends \DomainException {}

final class ListedException
{
    public int $mutable = 0;
}
