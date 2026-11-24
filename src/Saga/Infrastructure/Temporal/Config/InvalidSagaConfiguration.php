<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Config;

/** Raised while the container is compiled, so a broken timeout table never reaches a running worker (EC-21). */
final class InvalidSagaConfiguration extends \InvalidArgumentException {}
