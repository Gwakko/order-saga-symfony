<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Gateway;

/**
 * The shared breaker state cannot be read or written (Redis is down, EC-35).
 */
final class CircuitStateStoreUnavailable extends \RuntimeException {}
