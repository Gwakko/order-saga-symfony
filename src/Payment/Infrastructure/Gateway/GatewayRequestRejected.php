<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Gateway;

/**
 * The gateway answered with a 4xx other than a bank decline: the request itself is wrong (a bug on our side), a retry
 * cannot help. It is not a gateway failure, so the circuit breaker does not count it.
 */
final class GatewayRequestRejected extends \RuntimeException {}
